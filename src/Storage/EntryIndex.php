<?php

declare(strict_types=1);

namespace ElSchneider\GreenwashingChecker\Storage;

use ElSchneider\GreenwashingChecker\Scanning\EntryScanner;
use ElSchneider\GreenwashingChecker\Settings;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use JsonException;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Entry as Entries;
use Statamic\Facades\Site;
use UnexpectedValueException;

/**
 * Per-entry scan summaries: unit hashes, per-unit and total counts, display threshold and
 * `analysed_at`, stored as one atomic JSON file per entry named `sha256(entry_id).json`.
 * Survives cache clearing. A summary is marked stale when saved text hashes or the display
 * threshold differ, or when an approval was revoked after its finding record was pruned.
 */
final class EntryIndex
{
    public function __construct(private ?string $directory = null)
    {
        $this->directory ??= storage_path('statamic/greenwashing-checker/entries');
    }

    public static function counts(array $findings): array
    {
        $counts = ['open' => 0, 'high' => 0, 'ignored' => 0, 'approved' => 0];
        foreach ($findings as $finding) {
            if ($finding['approval'] !== null) {
                $counts['approved']++;
            } elseif ($finding['dismissed']) {
                $counts['ignored']++;
            } else {
                $counts['open']++;
                $counts['high'] += (int) ($finding['severity'] === 'high');
            }
        }

        return $counts;
    }

    public function find(string $id): ?array
    {
        $path = $this->path($id);

        // A missing file is an entry that has never completed a check.
        return File::exists($path) ? $this->read($path) : null;
    }

    public function record(Entry $entry, array $result): array
    {
        $units = [];
        $hashes = [];
        foreach ($result['units'] as $path => $unit) {
            $hashes[$path] = $unit['hash'];
            $units[$path] = self::counts($unit['findings']);
        }
        $record = [
            'id' => $entry->id(), 'site' => $entry->locale(), 'collection' => $entry->collectionHandle(),
            'title' => (string) $entry->get('title'), 'hashes' => $hashes, 'unit_counts' => $units,
            'counts' => self::total($units), 'analysed_at' => $result['analyzed_at'], 'threshold' => Settings::get('threshold'),
        ];
        Cache::lock('greenwashing-checker:index:'.$entry->id(), 10)->block(5, fn () => $this->write($record));

        return $record;
    }

    public function refreshDecision(string $hash): void
    {
        foreach (File::glob($this->directory.'/*.json') as $path) {
            $record = $this->read($path);
            if ($record === null || ! in_array($hash, $record['hashes'], true)) {
                continue;
            }
            Cache::lock('greenwashing-checker:index:'.$record['id'], 10)->block(5, function () use ($record, $hash): void {
                $current = $this->find($record['id']);
                if ($current === null) {
                    return;
                }
                $cache = app(UnitCache::class);
                if ($cache->get($hash) === null) {
                    // Approvals outlive pruned unit records. Keep last counts until recheck.
                    $current['decisions_stale'] = true;
                    $this->write($current);

                    return;
                }
                $counts = self::counts($cache->result(['unit' => $hash])['units']['unit']['findings']);
                foreach ($current['hashes'] as $path => $unitHash) {
                    if ($unitHash === $hash) {
                        $current['unit_counts'][$path] = $counts;
                    }
                }
                $current['counts'] = self::total($current['unit_counts']);
                $this->write($current);
            });
        }
    }

    public function rows(iterable $entries, int $page = 1): LengthAwarePaginator
    {
        $rows = [];
        foreach ($entries as $entry) {
            if (($record = $this->find($entry->id())) === null) {
                continue;
            }
            if ($record['counts']['open'] + $record['counts']['ignored'] + $record['counts']['approved'] === 0) {
                continue;
            }
            $record['title'] = (string) $entry->get('title');
            $rows[] = $record;
        }
        usort($rows, fn (array $a, array $b) => ($b['counts']['high'] <=> $a['counts']['high'])
            ?: ($b['counts']['open'] <=> $a['counts']['open'])
            ?: (($a['counts']['ignored'] > 0) <=> ($b['counts']['ignored'] > 0))
            ?: strcmp($a['title'], $b['title']));

        // Risk ordering needs all summaries; only visible rows need text walking and cache reads.
        $visible = collect($rows)->forPage($page, 50)->values()->map(function (array $record): array {
            $entry = Entries::find($record['id']);
            try {
                $hashes = app(EntryScanner::class)->prepare($entry)['hashes'];
                $record['stale'] = $record['hashes'] !== $hashes || $record['threshold'] !== Settings::get('threshold') || ($record['decisions_stale'] ?? false);
            } catch (UnitFileException $exception) {
                $record['stale'] = true;
                $record['error'] = $exception->getMessage();
            } catch (UnexpectedValueException $exception) {
                Log::warning('Greenwashing saved values could not be read.', ['entry' => $entry->id(), 'exception' => $exception]);
                $record['stale'] = true;
                $record['error'] = $exception->getMessage();
            }
            $record['edit_url'] = $entry->editUrl();
            $record['collection_title'] = $entry->collection()->title();
            $record['site_name'] = Site::get($entry->locale())->name();

            return $record;
        });

        return new LengthAwarePaginator($visible, count($rows), 50, $page);
    }

    private static function total(array $units): array
    {
        $counts = self::counts([]);
        foreach ($units as $unit) {
            foreach ($counts as $key => $count) {
                $counts[$key] += $unit[$key];
            }
        }

        return $counts;
    }

    private function read(string $path): ?array
    {
        try {
            $record = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($record)) {
                throw new UnexpectedValueException('Invalid entry summary.');
            }
            foreach (['id', 'site', 'collection', 'title', 'threshold'] as $key) {
                if (! is_string($record[$key] ?? null)) {
                    throw new UnexpectedValueException('Invalid entry summary field: '.$key);
                }
            }
            foreach (['hashes', 'unit_counts', 'counts'] as $key) {
                if (! is_array($record[$key] ?? null)) {
                    throw new UnexpectedValueException('Invalid entry summary field: '.$key);
                }
            }
            foreach ([$record['counts'], ...array_values($record['unit_counts'])] as $counts) {
                foreach (['open', 'high', 'ignored', 'approved'] as $key) {
                    if (! is_int($counts[$key] ?? null) || $counts[$key] < 0) {
                        throw new UnexpectedValueException('Invalid entry summary count: '.$key);
                    }
                }
            }
            if (! array_key_exists('analysed_at', $record) || ($record['analysed_at'] !== null && ! is_string($record['analysed_at']))) {
                throw new UnexpectedValueException('Invalid entry summary analysis date.');
            }
            foreach ($record['hashes'] as $hash) {
                if (! is_string($hash)) {
                    throw new UnexpectedValueException('Invalid entry summary hash.');
                }
            }

            return $record;
        } catch (JsonException|UnexpectedValueException $exception) {
            // Summaries are derived data. Keep the corrupt file for repair and skip it.
            Log::warning('Greenwashing entry summary could not be read.', ['path' => $path, 'exception' => $exception]);

            return null;
        }
    }

    private function path(string $id): string
    {
        return $this->directory.'/'.hash('sha256', $id).'.json';
    }

    private function write(array $record): void
    {
        File::ensureDirectoryExists($this->directory);
        File::replace($this->path($record['id']), json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
    }
}
