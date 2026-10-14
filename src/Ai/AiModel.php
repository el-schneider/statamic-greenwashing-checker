<?php

declare(strict_types=1);

namespace ElSchneider\GreenwashingChecker\Ai;

use Prism\Prism\Enums\Provider;

final readonly class AiModel
{
    private function __construct(public string $provider, public string $model) {}

    public static function fromString(string $value): self
    {
        $parts = explode('/', $value, 2);

        if (count($parts) !== 2 || Provider::tryFrom($parts[0]) === null || trim($parts[1]) === '' || trim($value) !== $value) {
            throw new AiException(AiErrorCode::Configuration);
        }

        return new self($parts[0], $parts[1]);
    }

    public function isConfigured(): bool
    {
        $credential = config('prism.providers.'.$this->provider.'.'.($this->provider === 'ollama' ? 'url' : 'api_key'));

        return is_string($credential) && trim($credential) !== '';
    }
}
