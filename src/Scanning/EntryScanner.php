<?php

declare(strict_types=1);

namespace ElSchneider\GreenwashingChecker\Scanning;

use ElSchneider\GreenwashingChecker\Analysis\ClaimAnalyzer;
use ElSchneider\GreenwashingChecker\Content\ContentWalker;
use ElSchneider\GreenwashingChecker\Content\TextUnit;
use ElSchneider\GreenwashingChecker\Settings;
use ElSchneider\GreenwashingChecker\Storage\EntryIndex;
use ElSchneider\GreenwashingChecker\Storage\UnitCache;
use Illuminate\Support\LazyCollection;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Fields\Field;

final class EntryScanner
{
    public function __construct(private ContentWalker $walker, private UnitCache $cache) {}

    public function entries(array $collections = [], ?string $site = null, mixed $user = null): LazyCollection
    {
        $collections = \Statamic\Facades\Collection::handles()
            ->filter(fn ($handle) => Settings::collectionEnabled($handle) && ($collections === [] || in_array($handle, $collections, true)))
            ->values()->all();
        // An empty whereIn('collection') means all collections in the Stache builder.
        if ($collections === []) {
            return LazyCollection::empty();
        }
        $query = Entry::query()->whereIn('collection', $collections);
        if ($site !== null) {
            $query->where('site', $site);
        }

        return $query->lazy(100)->filter(fn ($entry) => $user === null || $user->can('edit', $entry))->values();
    }

    public function prepare(EntryContract $entry): array
    {
        $units = $this->walker->walk($entry->blueprint()->fields(), $entry->data()->all(), fn (Field $field) => ($field->config()['greenwashing_checker'] ?? true) !== false);

        return $this->prepareUnits($units, Settings::analysis($entry->collectionHandle()));
    }

    /** @param list<TextUnit> $units */
    public function prepareUnits(array $units, array $rules): array
    {
        $hashes = $cached = $targets = [];
        foreach ($units as $unit) {
            $hash = $this->cache->hash($unit->text, $rules);
            $hashes[$unit->path] = $hash;
            if (($record = $this->cache->get($hash)) !== null) {
                $cached[$hash] = $record;
            } else {
                $targets[$hash] ??= $unit;
            }
        }
        $remaining = max(0, (int) config('statamic.greenwashing-checker.max_context_chars'));
        $context = [];
        // Short fields preserve specifications when a long Bard body exceeds the budget.
        foreach (collect($units)->sortBy(fn (TextUnit $unit) => $unit->path === 'title' ? -1 : mb_strlen($unit->text)) as $unit) {
            if ($remaining === 0) {
                break;
            }
            $text = mb_substr($unit->text, 0, $remaining);
            $context[] = new TextUnit($unit->path, $unit->type, $text, $unit->display);
            $remaining -= mb_strlen($text);
        }

        return ['hashes' => $hashes, 'cached' => $cached, 'targets' => array_values($targets), 'context' => $context, 'rules' => $rules];
    }

    public function prune(bool $dryRun = false): int
    {
        $hashes = [];
        // Always walk current saved text in every enabled collection/site, not filtered or stale summaries.
        // Finish collecting before deleting anything: unreadable entries must not lose their results.
        foreach ($this->entries() as $entry) {
            foreach ($this->prepare($entry)['hashes'] as $hash) {
                $hashes[$hash] = true;
            }
        }

        return $this->cache->prune(array_keys($hashes), $dryRun);
    }

    public function scan(EntryContract $entry, string $locale = 'de'): array
    {
        $plan = $this->prepare($entry);
        $findings = app(ClaimAnalyzer::class)->analyze($plan['targets'], $plan['context'], $locale, Site::get($entry->locale())->locale(), $plan['rules']);
        foreach ($plan['targets'] as $unit) {
            $this->cache->put($plan['hashes'][$unit->path], $findings[$unit->path]);
        }
        $result = $this->cache->result($plan['hashes'], $plan['cached']);

        return app(EntryIndex::class)->record($entry, $result);
    }
}
