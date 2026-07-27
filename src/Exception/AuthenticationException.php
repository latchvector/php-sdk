<?php

declare(strict_types=1);

namespace LatchVector\Sso\Exception;

/** Wrong password, bad MFA code, or a rejected social ID token. */
class AuthenticationException extends SsoException
{
}
