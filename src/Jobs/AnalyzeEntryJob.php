<?php

declare(strict_types=1);

namespace ElSchneider\GreenwashingChecker\Jobs;

use ElSchneider\GreenwashingChecker\Ai\AiException;
use ElSchneider\GreenwashingChecker\Ai\TrackedJobs;
use ElSchneider\GreenwashingChecker\Analysis\ClaimAnalyzer;
use ElSchneider\GreenwashingChecker\Content\TextUnit;
use ElSchneider\GreenwashingChecker\Storage\UnitCache;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class AnalyzeEntryJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** @param list<TextUnit> $targets
     * @param  list<TextUnit>  $context
     * @param  array<string, string>  $hashes
     */
    public function __construct(
        public string $jobId,
        public array $targets,
        public array $context,
        public string $explanationLocale,
        public string $contentLocale,
        public array $hashes,
        public array $cached,
        public string $reference,
        public ?array $rules = null,
    ) {}

    public function handle(TrackedJobs $jobs, ClaimAnalyzer $analyzer, UnitCache $cache): void
    {
        $jobs->running($this->jobId);
        try {
            $jobs->heartbeat($this->jobId);
            $findings = $analyzer->analyze($this->targets, $this->context, $this->explanationLocale, $this->contentLocale, $this->rules);
            $jobs->heartbeat($this->jobId);
            foreach ($this->targets as $unit) {
                $cache->put($this->hashes[$unit->path], $findings[$unit->path]);
            }
            $result = $cache->result($this->hashes, $this->cached);
            if (str_starts_with($this->reference, 'entry::') && ($entry = \Statamic\Facades\Entry::find(substr($this->reference, 7))) !== null) {
                app(\ElSchneider\GreenwashingChecker\Storage\EntryIndex::class)->record($entry, $result);
            }
            $jobs->complete($this->jobId, $result);
        } catch (Throwable $exception) {
            $this->failed($exception);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $exception ??= new RuntimeException('Queue worker failed.');
        $error = AiException::from($exception);
        Log::error('greenwashing-checker: analysis failed', [
            'exception' => $exception, 'code' => $error->errorCode->value,
            'model' => config('statamic.greenwashing-checker.model'), 'job' => $this->jobId,
            'entry' => $this->reference,
        ]);
        app(TrackedJobs::class)->fail($this->jobId, $error);
    }
}
