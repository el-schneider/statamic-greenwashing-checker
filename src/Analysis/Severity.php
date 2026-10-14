<?php

declare(strict_types=1);

namespace ElSchneider\GreenwashingChecker\Analysis;

enum Severity: string
{
    case High = 'high';
    case Medium = 'medium';
}
