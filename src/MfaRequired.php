<?php

declare(strict_types=1);

namespace LatchVector\Sso;

/**
 * The password was correct, but a second factor is still owed.
 *
 * The pending token is NOT an access token. It carries no permissions and
 * is accepted by exactly one endpoint, via SsoClient::verifyMfa().
 */
final class MfaRequired implements LoginResult
{
    public function __construct(public readonly string $pendingToken)
    {
    }
}
