<?php

declare(strict_types=1);

namespace ElSchneider\GreenwashingChecker\Ai;

use Carbon\CarbonImmutable;

final readonly class JobState
{
    public function __construct(
        public string $id,
        public string $scope,
        public string|int $ownerId,
        public JobStatus $status,
        public ?array $result,
        public ?array $error,
        public CarbonImmutable $updatedAt,
    ) {}
}
