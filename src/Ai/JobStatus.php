<?php

declare(strict_types=1);

namespace ElSchneider\GreenwashingChecker\Ai;

enum JobStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
}
