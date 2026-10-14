<?php

declare(strict_types=1);

use ElSchneider\GreenwashingChecker\Ai\AiErrorCode;
use ElSchneider\GreenwashingChecker\Ai\AiException;
use ElSchneider\GreenwashingChecker\Ai\JobStatus;
use ElSchneider\GreenwashingChecker\Ai\TrackedJobs;
use Illuminate\Support\Str;

it('is a singleton and tracks a successful lifecycle', function () {
    $jobs = app(TrackedJobs::class);
    expect(app(TrackedJobs::class))->toBe($jobs);
    $id = $jobs->create('checker', 123);
    expect(Str::isUuid($id))->toBeTrue();
    $state = $jobs->find($id);
    expect($state->scope)->toBe('checker')->and($state->ownerId)->toBe(123)
        ->and($state->status)->toBe(JobStatus::Queued)->and($state->result)->toBeNull()->and($state->error)->toBeNull();
    $jobs->running($id);
    expect($jobs->find($id)->status)->toBe(JobStatus::Running);
    $this->travel(100)->seconds();
    $jobs->heartbeat($id);
    expect($jobs->find($id)->updatedAt->greaterThan($state->updatedAt))->toBeTrue();
    $this->travel(100)->seconds();
    expect($jobs->find($id)->status)->toBe(JobStatus::Running);
    $jobs->complete($id, ['findings' => []]);
    expect($jobs->find($id)->status)->toBe(JobStatus::Completed)
        ->and($jobs->find($id)->result)->toBe(['findings' => []])->and($jobs->find($id)->error)->toBeNull();
});

it('tracks safe failures and clears an error on a retry', function () {
    $jobs = app(TrackedJobs::class);
    $id = $jobs->create('checker', 'owner');
    $jobs->fail($id, new AiException(AiErrorCode::RateLimited));
    expect($jobs->find($id)->status)->toBe(JobStatus::Failed)
        ->and($jobs->find($id)->error['code'])->toBe('rate_limited')->and($jobs->find($id)->result)->toBeNull();
    $jobs->running($id);
    expect($jobs->find($id)->error)->toBeNull();
    $jobs->complete($id, ['ok' => true]);
    expect($jobs->find($id)->result)->toBe(['ok' => true]);
});

it('reports stale running jobs but not queued or terminal jobs', function () {
    $this->freezeTime();
    $jobs = app(TrackedJobs::class);
    $running = $jobs->create('checker', 'owner');
    $queued = $jobs->create('checker', 'owner');
    $completed = $jobs->create('checker', 'owner');
    $jobs->running($running);
    $jobs->complete($completed, []);
    $this->travel(120)->seconds();
    expect($jobs->find($running)->status)->toBe(JobStatus::Running);
    $this->travel(1)->seconds();
    expect($jobs->find($running)->status)->toBe(JobStatus::Failed)
        ->and($jobs->find($running)->error['code'])->toBe('stale')
        ->and($jobs->find($running)->error['retryable'])->toBeTrue()
        ->and($jobs->find($queued)->status)->toBe(JobStatus::Queued)
        ->and($jobs->find($completed)->status)->toBe(JobStatus::Completed);
});

it('expires cached jobs after one hour', function () {
    $jobs = app(TrackedJobs::class);
    $id = $jobs->create('checker', 'owner');
    $this->travel(3601)->seconds();
    expect($jobs->find($id))->toBeNull()->and($jobs->find('missing'))->toBeNull();
});

it('fails loudly when updating a missing job', function () {
    app(TrackedJobs::class)->running('missing');
})->throws(LogicException::class);

it('round trips jobs through a persistent cache that forbids object deserialization', function () {
    $directory = sys_get_temp_dir().'/greenwashing-jobs-'.Str::uuid();
    $files = new Illuminate\Filesystem\Filesystem;
    $cache = new Illuminate\Cache\Repository(new Illuminate\Cache\FileStore($files, $directory, null, false));
    try {
        $jobs = new TrackedJobs($cache);
        $id = $jobs->create('checker', 123);
        expect($jobs->find($id)->ownerId)->toBe(123);
        $jobs->running($id);
        $jobs->heartbeat($id);
        $jobs->complete($id, ['units' => ['body' => ['findings' => []]]]);
        $reopened = new TrackedJobs(new Illuminate\Cache\Repository(new Illuminate\Cache\FileStore($files, $directory, null, false)));
        expect($reopened->find($id)->status)->toBe(JobStatus::Completed)
            ->and($reopened->find($id)->result)->toBe(['units' => ['body' => ['findings' => []]]]);
        $reopened->fail($id, new AiException(AiErrorCode::Unavailable));
        expect($reopened->find($id)->error['code'])->toBe('unavailable');
    } finally {
        $files->deleteDirectory($directory);
    }
});
