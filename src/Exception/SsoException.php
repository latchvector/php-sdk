<?php

declare(strict_types=1);

namespace LatchVector\Sso\Exception;

use RuntimeException;

/**
 * Base for every exception raised by this SDK.
 *
 * The subclasses exist as distinct types rather than a string field so
 * that RefreshTokenReusedException cannot be swept up by a catch block
 * that meant to retry a network blip. See CONTRACT.md section 5.
 */
class SsoException extends RuntimeException
{
    /**
     * @param string $errorCode The service's error code, e.g. "access_denied".
     *                          Named errorCode rather than code because
     *                          \Exception already declares a non-readonly
     *                          int $code, and redeclaring it as a readonly
     *                          string is a fatal error.
     */
    public function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        ?string $message = null,
    ) {
        parent::__construct($message ?? $errorCode);
    }

    /**
     * Whether retrying the identical request could plausibly succeed.
     *
     * Only rate limiting qualifies. A 403 is a decision the service
     * already made; retrying it produces a stream of ACCESS_DENIED audit
     * entries that a compliance officer will eventually ask about.
     */
    public function isRetryable(): bool
    {
        return false;
    }
}
