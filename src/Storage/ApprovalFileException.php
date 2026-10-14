<?php

declare(strict_types=1);

namespace ElSchneider\GreenwashingChecker\Storage;

use RuntimeException;
use Throwable;

final class ApprovalFileException extends RuntimeException
{
    public function __construct(string $path, ?Throwable $previous = null)
    {
        // The client needs the file name, never the server's private absolute directory.
        parent::__construct(__('greenwashing-checker::messages.approval_file_error', ['file' => basename($path)]), previous: $previous);
    }
}
