<?php

declare(strict_types=1);

namespace ElSchneider\GreenwashingChecker\Storage;

use RuntimeException;
use Throwable;

final class UnitFileException extends RuntimeException
{
    public function __construct(string $path, ?Throwable $previous = null)
    {
        // Clients need the damaged file name, not the server's private storage directory.
        parent::__construct(__('greenwashing-checker::messages.unit_file_error', ['file' => basename($path)]), previous: $previous);
    }
}
