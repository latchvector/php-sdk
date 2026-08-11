<?php

declare(strict_types=1);

namespace LatchVector\Sso\Tenancy;

/**
 * Thrown when a caller asks to filter by an org that is outside the reach their
 * token grants — an attempt to look at a branch they are not allowed to see.
 *
 * Map it to HTTP 403 in your framework's exception handler (the Laravel scope
 * already raises Laravel's AuthorizationException when that class is available).
 */
final class OrgReachException extends \RuntimeException
{
}
