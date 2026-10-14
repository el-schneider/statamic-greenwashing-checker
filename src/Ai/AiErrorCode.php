<?php

declare(strict_types=1);

namespace ElSchneider\GreenwashingChecker\Ai;

enum AiErrorCode: string
{
    case Configuration = 'configuration';
    case Authentication = 'authentication';
    case RateLimited = 'rate_limited';
    case Unavailable = 'unavailable';
    case InvalidResponse = 'invalid_response';
    case Unexpected = 'unexpected';
    case ApprovalFile = 'approval_file';
    case UnitFile = 'unit_file';
}
