<?php

declare(strict_types=1);

namespace LatchVector\Sso\Exception;

/** The token failed verification. Details are intentionally coarse. */
class TokenVerificationException extends SsoException
{
    public function __construct(string $message)
    {
        parent::__construct('invalid_token', 401, $message);
    }
}
