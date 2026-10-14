<?php

declare(strict_types=1);

namespace ElSchneider\GreenwashingChecker\Ai;

use ElSchneider\GreenwashingChecker\Storage\ApprovalFileException;
use ElSchneider\GreenwashingChecker\Storage\UnitFileException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Exceptions\PrismStructuredDecodingException;
use RuntimeException;
use Throwable;

final class AiException extends RuntimeException
{
    public function __construct(public readonly AiErrorCode $errorCode, ?Throwable $previous = null)
    {
        parent::__construct(match ($errorCode) {
            AiErrorCode::Configuration => 'The AI model or provider credentials are not configured correctly.',
            AiErrorCode::Authentication => 'The AI provider rejected the credentials.',
            AiErrorCode::RateLimited => 'The AI provider rate limited the request. Try again later.',
            AiErrorCode::Unavailable => 'The AI provider is temporarily unavailable. Try again later.',
            AiErrorCode::InvalidResponse => 'The AI provider returned an invalid response.',
            AiErrorCode::Unexpected => 'An unexpected AI error occurred.',
            AiErrorCode::ApprovalFile, AiErrorCode::UnitFile => $previous->getMessage(),
        }, previous: $previous);
    }

    public static function from(Throwable $exception): self
    {
        if ($exception instanceof self) {
            return $exception;
        }
        if ($exception instanceof ApprovalFileException) {
            return new self(AiErrorCode::ApprovalFile, $exception);
        }

        if ($exception instanceof UnitFileException) {
            return new self(AiErrorCode::UnitFile, $exception);
        }

        return new self(self::classify($exception), $exception);
    }

    public function retryable(): bool
    {
        return in_array($this->errorCode, [AiErrorCode::RateLimited, AiErrorCode::Unavailable], true);
    }

    /** @return array{code: string, message: string, retryable: bool} */
    public function toArray(): array
    {
        return ['code' => $this->errorCode->value, 'message' => $this->getMessage(), 'retryable' => $this->retryable()];
    }

    private static function classify(Throwable $exception): AiErrorCode
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof PrismStructuredDecodingException) {
                return AiErrorCode::InvalidResponse;
            }
            if ($current instanceof PrismRateLimitedException) {
                return AiErrorCode::RateLimited;
            }
            if ($current instanceof ConnectionException) {
                return AiErrorCode::Unavailable;
            }

            $status = $current instanceof RequestException ? $current->response->status() : $current->getCode();
            if ($current instanceof PrismException || $current instanceof RequestException) {
                if (in_array($status, [401, 403], true)) {
                    return AiErrorCode::Authentication;
                }
                if ($status === 429) {
                    return AiErrorCode::RateLimited;
                }
                if (is_int($status) && $status >= 500 && $status < 600) {
                    return AiErrorCode::Unavailable;
                }
            }
        }

        // Older Prism transports embed provider status and failure details in the message.
        if ($exception instanceof PrismException) {
            $message = mb_strtolower($exception->getMessage());
            if (preg_match('/\b(401|403)\b|unauthori(?:s|z)ed|forbidden|authentication|api(?:_|-| )key/', $message)) {
                return AiErrorCode::Authentication;
            }
            if (preg_match('/\b429\b|rate limit|too many requests|quota exceeded/', $message)) {
                return AiErrorCode::RateLimited;
            }
            if (preg_match('/\b5\d{2}\b|connection|timeout|timed out|temporarily unavailable|server error|overloaded|network/', $message)) {
                return AiErrorCode::Unavailable;
            }
        }

        return AiErrorCode::Unexpected;
    }
}
