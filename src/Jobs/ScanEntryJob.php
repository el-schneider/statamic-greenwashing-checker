<?php

declare(strict_types=1);

namespace ElSchneider\GreenwashingChecker\Jobs;

use ElSchneider\GreenwashingChecker\Ai\AiException;
use ElSchneider\GreenwashingChecker\Ai\TrackedJobs;
use ElSchneider\GreenwashingChecker\Scanning\EntryScanner;
use ElSchneider\GreenwashingChecker\Settings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Statamic\Facades\Entry;
use Statamic\Facades\User;
use Throwable;

final class ScanEntryJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public string $jobId, public string $entryId, public string $ownerId, public string $locale) {}

    public function handle(TrackedJobs $jobs, EntryScanner $scanner): void
    {
        try {
            $jobs->running($this->jobId);
            $entry = Entry::find($this->entryId);
            $user = User::find($this->ownerId);
            if ($entry === null || $user === null || ! $user->can('scan greenwashing findings') || ! $user->can('edit', $entry) || ! Settings::collectionEnabled($entry->collectionHandle())) {
                throw new RuntimeException('The entry is unavailable or scan access was revoked.');
            }
            // A duplicate needs no provider call. The active scan will refresh the summary.
            $record = Cache::lock('greenwashing-checker:scan:'.$this->entryId, 130)->get(fn () => $scanner->scan($entry, $this->locale));
            $jobs->complete($this->jobId, $record === false
                ? ['skipped' => true, 'message' => __('greenwashing-checker::scan.already_running', locale: $this->locale)]
                : $record);
        } catch (Throwable $exception) {
            $this->failed($exception);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $exception ??= new RuntimeException('Queue worker failed.');
        Log::error('greenwashing-checker: queued scan failed', ['entry' => $this->entryId, 'job' => $this->jobId, 'exception' => $exception]);
        app(TrackedJobs::class)->fail($this->jobId, AiException::from($exception));
    }
}
