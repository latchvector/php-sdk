<?php

declare(strict_types=1);

namespace LatchVector\Sso\Exception;

/** Rate limited. The only retryable error. */
class RateLimitException extends SsoException
{
    public function __construct(public readonly ?float $retryAfterSeconds = null)
    {
        parent::__construct('too_many_requests', 429, 'Rate limited');
    }

    public function isRetryable(): bool
    {
        return true;
    }
}
