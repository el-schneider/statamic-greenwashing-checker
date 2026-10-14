<?php

declare(strict_types=1);

use ElSchneider\GreenwashingChecker\Ai\AiErrorCode;
use ElSchneider\GreenwashingChecker\Ai\AiException;
use ElSchneider\GreenwashingChecker\Ai\AiModel;

it('parses provider and model without losing nested slashes', function (string $input, string $provider, string $model) {
    $parsed = AiModel::fromString($input);
    expect($parsed->provider)->toBe($provider)->and($parsed->model)->toBe($model);
})->with([
    ['openai/gpt-6-luna', 'openai', 'gpt-6-luna'],
    ['openrouter/openai/gpt-6-luna', 'openrouter', 'openai/gpt-6-luna'],
    ['ollama/llama3:latest', 'ollama', 'llama3:latest'],
]);

it('rejects invalid model configuration with a safe error', function (string $input) {
    try {
        AiModel::fromString($input);
        test()->fail('Invalid configuration was accepted.');
    } catch (AiException $exception) {
        expect($exception->errorCode)->toBe(AiErrorCode::Configuration)
            ->and($exception->retryable())->toBeFalse()
            ->and($exception->toArray()['code'])->toBe('configuration')
            ->and($exception->getMessage())->not->toContain('sk-secret');
    }
})->with(['', 'openai', '/model', 'openai/', 'unknown/model', 'openai/   ', ' openai/sk-secret']);

it('checks credentials instead of a cloud provider URL', function () {
    $model = AiModel::fromString('openai/gpt-6-luna');
    config(['prism.providers.openai' => ['url' => 'https://example.com', 'api_key' => '']]);
    expect($model->isConfigured())->toBeFalse();
    config(['prism.providers.openai.api_key' => '   ']);
    expect($model->isConfigured())->toBeFalse();
    config(['prism.providers.openai.api_key' => 'test-key']);
    expect($model->isConfigured())->toBeTrue();
    config(['prism.providers.openai.api_key' => null]);
    expect($model->isConfigured())->toBeFalse();
});

it('checks a local provider URL without needing an API key', function () {
    $model = AiModel::fromString('ollama/llama3');
    config(['prism.providers.ollama' => ['url' => 'http://localhost:11434']]);
    expect($model->isConfigured())->toBeTrue();
    config(['prism.providers.ollama.url' => '']);
    expect($model->isConfigured())->toBeFalse();
});
