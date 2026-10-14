<?php

declare(strict_types=1);

namespace ElSchneider\GreenwashingChecker\Console;

use ElSchneider\GreenwashingChecker\Ai\AiException;
use ElSchneider\GreenwashingChecker\Scanning\EntryScanner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Statamic\Facades\Collection;
use Statamic\Facades\Site;
use Throwable;
use UnexpectedValueException;

final class ScanCommand extends Command
{
    protected $signature = 'greenwashing:scan {--collection=*} {--site=} {--dry-run} {--prune}';

    protected $description = 'Check saved entries for greenwashing risks (dry-run estimates uncached text).';

    public function handle(EntryScanner $scanner): int
    {
        foreach ($this->option('collection') as $collection) {
            if (Collection::findByHandle($collection) === null) {
                $this->error('Unknown collection: '.$collection);

                return self::FAILURE;
            }
        }
        $site = $this->option('site');
        if ($site !== null && Site::get($site) === null) {
            $this->error('Unknown site: '.$site);

            return self::FAILURE;
        }
        $rows = [];
        $failed = 0;
        $seen = [];
        $totals = ['entries' => 0, 'units' => 0, 'uncached' => 0, 'characters' => 0];
        foreach ($scanner->entries($this->option('collection'), $site) as $entry) {
            $label = $entry->get('title').' ['.$entry->id().'; '.$entry->locale().']';
            try {
                if ($this->option('dry-run')) {
                    $plan = $scanner->prepare($entry);
                    $uncached = $characters = 0;
                    foreach ($plan['targets'] as $unit) {
                        $hash = $plan['hashes'][$unit->path];
                        if (! isset($seen[$hash])) {
                            $seen[$hash] = true;
                            $uncached++;
                            $characters += mb_strlen($unit->text);
                        }
                    }
                    $totals['entries']++;
                    $totals['units'] += count($plan['hashes']);
                    $totals['uncached'] += $uncached;
                    $totals['characters'] += $characters;
                    $rows[] = [$label, count($plan['hashes']), $uncached, $characters];
                } else {
                    $record = $scanner->scan($entry);
                    $rows[] = [$label, ...array_values($record['counts'])];
                }
            } catch (Throwable $exception) {
                $failed++;
                Log::error('greenwashing-checker: scan failed', ['entry' => $entry->id(), 'exception' => $exception]);
                $this->error($label.': '.($exception instanceof UnexpectedValueException ? $exception->getMessage() : AiException::from($exception)->getMessage()));
            }
        }
        $this->table($this->option('dry-run') ? ['Entry', 'Units', 'Uncached units', 'Uncached characters'] : ['Entry', 'Open', 'High', 'Ignored', 'Approved'], $rows);
        if ($this->option('dry-run')) {
            $this->line(implode(' · ', array_map(fn ($key, $value) => $key.': '.$value, array_keys($totals), $totals)));
            $this->line('No provider calls. Characters estimate new target text, not tokens, context, output or a currency price. Concurrent jobs may analyze shared uncached text more than once.');
        }
        $pruneFailed = false;
        if ($this->option('prune') || ($this->option('collection') === [] && $site === null)) {
            try {
                $count = $scanner->prune((bool) $this->option('dry-run'));
                $this->line(($this->option('dry-run') ? 'Would prune unit files: ' : 'Pruned unit files: ').$count);
            } catch (Throwable $exception) {
                $pruneFailed = true;
                Log::error('greenwashing-checker: pruning failed', ['exception' => $exception]);
                $this->error('Pruning failed: '.($exception instanceof UnexpectedValueException ? $exception->getMessage() : AiException::from($exception)->getMessage()));
            }
        }
        $this->line('Failed entries: '.$failed);

        return $failed > 0 || $pruneFailed ? self::FAILURE : self::SUCCESS;
    }
}
