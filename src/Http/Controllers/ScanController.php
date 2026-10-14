<?php

declare(strict_types=1);

namespace ElSchneider\GreenwashingChecker\Http\Controllers;

use ElSchneider\GreenwashingChecker\Ai\AiException;
use ElSchneider\GreenwashingChecker\Ai\TrackedJobs;
use ElSchneider\GreenwashingChecker\Jobs\ScanEntryJob;
use ElSchneider\GreenwashingChecker\Scanning\EntryScanner;
use ElSchneider\GreenwashingChecker\Storage\EntryIndex;
use ElSchneider\GreenwashingChecker\Storage\UnitCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Statamic\Facades\User;
use Throwable;

final class ScanController
{
    public function index(Request $request, EntryScanner $scanner, EntryIndex $index): JsonResponse
    {
        $entries = $this->entries($request, $scanner);

        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        $page = $index->rows($entries, (int) ($data['page'] ?? 1));

        return response()->json(['rows' => $page->items(), 'meta' => [
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(), 'total' => $page->total(),
            'from' => $page->firstItem(), 'to' => $page->lastItem(),
        ]]);
    }

    public function preview(Request $request, EntryScanner $scanner): JsonResponse
    {
        $rows = [];
        $seen = [];
        $totals = ['entries' => 0, 'units' => 0, 'uncached' => 0, 'characters' => 0];
        foreach ($this->entries($request, $scanner) as $entry) {
            $row = ['id' => $entry->id(), 'title' => (string) $entry->get('title')];
            $totals['entries']++;
            try {
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
                $row += ['units' => count($plan['hashes']), 'uncached' => $uncached, 'characters' => $characters];
                foreach (['units', 'uncached', 'characters'] as $key) {
                    $totals[$key] += $row[$key];
                }
            } catch (Throwable $exception) {
                Log::error('greenwashing-checker: scan preview failed', ['entry' => $entry->id(), 'exception' => $exception]);
                $row['error'] = AiException::from($exception)->getMessage();
            }
            $rows[] = $row;
        }

        return response()->json(['entries' => $rows, 'totals' => $totals]);
    }

    public function start(Request $request, EntryScanner $scanner, TrackedJobs $jobs, UnitCache $cache, EntryIndex $index): JsonResponse
    {
        $queued = [];
        $skipped = 0;
        $user = User::current();
        foreach ($this->entries($request, $scanner) as $entry) {
            try {
                $plan = $scanner->prepare($entry);
                if ($plan['targets'] === []) {
                    $index->record($entry, $cache->result($plan['hashes'], $plan['cached']));
                    $skipped++;

                    continue;
                }
            } catch (Throwable $exception) {
                // Use the tracked job's error boundary for malformed values or failed cache refreshes.
                Log::warning('greenwashing-checker: cache-only refresh failed; queueing entry', ['entry' => $entry->id(), 'exception' => $exception]);
            }
            $id = $jobs->create('greenwashing-checker-scan', $user->id());
            ScanEntryJob::dispatch($id, $entry->id(), (string) $user->id(), $user->preferredLocale());
            $queued[] = ['id' => $id, 'entry' => $entry->id(), 'title' => (string) $entry->get('title'), 'poll' => cp_route('utilities.greenwashing-scan.jobs.show', ['job' => $id])];
        }

        return response()->json(['jobs' => $queued, 'skipped' => $skipped], 202);
    }

    private function entries(Request $request, EntryScanner $scanner): \Illuminate\Support\LazyCollection
    {
        abort_unless(User::current()?->can('scan greenwashing findings'), 403);
        $data = $request->validate(['collection' => ['nullable', 'string', \Illuminate\Validation\Rule::in(\Statamic\Facades\Collection::all()->map->handle()->all())]]);

        return $scanner->entries(empty($data['collection']) ? [] : [$data['collection']], user: User::current());
    }
}
