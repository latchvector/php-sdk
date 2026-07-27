<?php

declare(strict_types=1);

namespace LatchVector\Sso\Exception;

/**
 * An already-rotated refresh token was presented.
 *
 * A security event, not a failure. Either this client lost track of a
 * rotation, or someone else holds a copy of a token this client also
 * holds. Discard local session state, re-authenticate, and alert.
 *
 * Deliberately NOT a subclass of RefreshTokenException, so a catch block
 * that only meant to refresh-or-relogin cannot swallow it.
 */
class RefreshTokenReusedException extends SsoException
{
    public function __construct(?string $message = null)
    {
        parent::__construct(
            'refresh_token_reused',
            401,
            $message ?? 'Refresh token reuse detected — treat as compromise',
        );
    }
}
