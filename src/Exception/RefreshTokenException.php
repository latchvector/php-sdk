<?php

declare(strict_types=1);

namespace LatchVector\Sso\Exception;

/** The refresh token is expired, unknown, or malformed. Re-authenticate. */
class RefreshTokenException extends SsoException
{
}
