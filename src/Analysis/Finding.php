<?php

declare(strict_types=1);

namespace ElSchneider\GreenwashingChecker\Analysis;

use JsonSerializable;

final readonly class Finding implements JsonSerializable
{
    /** @param list<string> $suggestions */
    public function __construct(
        public string $id,
        public string $path,
        public string $quote,
        public bool $anchored,
        public Category $category,
        public Severity $severity,
        public string $explanation,
        public array $suggestions,
        public bool $dismissed = false,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id, 'path' => $this->path, 'quote' => $this->quote,
            'anchored' => $this->anchored, 'category' => $this->category->value,
            'severity' => $this->severity->value, 'explanation' => $this->explanation,
            'suggestions' => $this->suggestions, 'dismissed' => $this->dismissed,
        ];
    }
}
