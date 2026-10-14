<?php

declare(strict_types=1);

namespace ElSchneider\GreenwashingChecker\Content;

final readonly class TextUnit
{
    /** @param 'text'|'textarea'|'markdown'|'bard' $type */
    public function __construct(public string $path, public string $type, public string $text, public string $display) {}
}
