<?php

declare(strict_types=1);

use ElSchneider\GreenwashingChecker\Ai\AiErrorCode;
use ElSchneider\GreenwashingChecker\Ai\AiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Exceptions\PrismStructuredDecodingException;

it('maps HTTP failures, including when wrapped by Prism', function (int $status, AiErrorCode $expected, bool $retryable) {
    Http::fake(['*' => Http::response('raw-provider-body sk-secret', $status)]);
    $original = Http::get('https://provider.example.test')->toException();
    expect($original)->toBeInstanceOf(RequestException::class);
    foreach ([$original, PrismException::providerRequestError('model', $original)] as $exception) {
        $mapped = AiException::from($exception);
        expect($mapped->errorCode)->toBe($expected)
            ->and($mapped->retryable())->toBe($retryable)
            ->and($mapped->toArray())->toBe([
                'code' => $expected->value, 'message' => $mapped->getMessage(), 'retryable' => $retryable,
            ])
            ->and($mapped->getMessage())->not->toContain('raw-provider-body', 'sk-secret')
            ->and($mapped->getPrevious())->toBe($exception);
    }
})->with([
    [401, AiErrorCode::Authentication, false],
    [403, AiErrorCode::Authentication, false],
    [429, AiErrorCode::RateLimited, true],
    [500, AiErrorCode::Unavailable, true],
    [503, AiErrorCode::Unavailable, true],
    [422, AiErrorCode::Unexpected, false],
]);

it('maps Prism and transport errors to safe codes', function (Closure $make, AiErrorCode $code) {
    $exception = AiException::from($make());
    expect($exception->errorCode)->toBe($code)->and($exception->getMessage())->not->toContain('sk-secret');
})->with([
    [fn () => PrismRateLimitedException::make(), AiErrorCode::RateLimited],
    [fn () => PrismStructuredDecodingException::make('sk-secret'), AiErrorCode::InvalidResponse],
    [fn () => new ConnectionException('sk-secret'), AiErrorCode::Unavailable],
    [fn () => new PrismException('OpenAI Error [401]: sk-secret'), AiErrorCode::Authentication],
    [fn () => new PrismException('quota exceeded sk-secret'), AiErrorCode::RateLimited],
    [fn () => new PrismException('network timeout sk-secret'), AiErrorCode::Unavailable],
    [fn () => new PrismException('unknown sk-secret'), AiErrorCode::Unexpected],
    [fn () => new RuntimeException('sk-secret', 401), AiErrorCode::Unexpected],
]);

it('classifies repeatedly without logging and preserves the original exception', function () {
    Log::spy();
    $original = new RuntimeException('original failure');
    $mapped = AiException::from($original);
    expect(AiException::from($original)->getPrevious())->toBe($original)
        ->and($mapped->getPrevious())->toBe($original)
        ->and(AiException::from($mapped))->toBe($mapped);
    Log::shouldNotHaveReceived('error');
});

it('keeps explicitly created configuration errors safe and non-retryable', function () {
    $error = new AiException(AiErrorCode::Configuration);
    expect(AiException::from($error))->toBe($error)->and($error->toArray()['retryable'])->toBeFalse();
});
