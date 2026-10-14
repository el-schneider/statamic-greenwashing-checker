<?php

declare(strict_types=1);

namespace ElSchneider\GreenwashingChecker\Ai;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Str;
use LogicException;

final class TrackedJobs
{
    private const PREFIX = 'greenwashing-checker:job:';

    private const TTL = 3600;

    public function __construct(private readonly Repository $cache) {}

    public function create(string $scope, string|int $ownerId): string
    {
        $id = (string) Str::uuid();
        $this->cache->put(self::PREFIX.$id, [
            'id' => $id, 'scope' => $scope, 'ownerId' => $ownerId,
            'status' => JobStatus::Queued->value, 'result' => null, 'error' => null,
            'updatedAt' => CarbonImmutable::now()->toISOString(),
        ], self::TTL);

        return $id;
    }

    public function running(string $id): void
    {
        $this->update($id, JobStatus::Running);
    }

    public function heartbeat(string $id): void
    {
        $job = $this->requireJob($id);
        $this->update($id, $job->status, $job->result, $job->error);
    }

    public function complete(string $id, array $result): void
    {
        $this->update($id, JobStatus::Completed, $result);
    }

    public function fail(string $id, AiException $e): void
    {
        $this->update($id, JobStatus::Failed, error: $e->toArray());
    }

    public function find(string $id): ?JobState
    {
        $job = $this->read($id);
        if ($job === null) {
            return null;
        }

        if ($job->status === JobStatus::Running && $job->updatedAt->addSeconds(120)->isPast()) {
            return new JobState($job->id, $job->scope, $job->ownerId, JobStatus::Failed, null, [
                'code' => 'stale', 'message' => 'The AI job stopped responding. Try again.', 'retryable' => true,
            ], $job->updatedAt);
        }

        return $job;
    }

    private function requireJob(string $id): JobState
    {
        return $this->read($id) ?? throw new LogicException('Tracked job not found: '.$id);
    }

    private function update(string $id, JobStatus $status, ?array $result = null, ?array $error = null): void
    {
        $job = $this->requireJob($id);
        $this->cache->put(self::PREFIX.$id, [
            'id' => $id, 'scope' => $job->scope, 'ownerId' => $job->ownerId,
            'status' => $status->value, 'result' => $result, 'error' => $error,
            'updatedAt' => CarbonImmutable::now()->toISOString(),
        ], self::TTL);
    }

    private function read(string $id): ?JobState
    {
        $record = $this->cache->get(self::PREFIX.$id);
        if ($record === null) {
            return null;
        }

        // Laravel 13 forbids cached object deserialization by default.
        return new JobState(
            $record['id'], $record['scope'], $record['ownerId'], JobStatus::from($record['status']),
            $record['result'], $record['error'], CarbonImmutable::parse($record['updatedAt']),
        );
    }
}
