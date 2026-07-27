<?php

declare(strict_types=1);

namespace LatchVector\Sso\Exception;

/** Authenticated, but not permitted. Never retry. */
class AccessDeniedException extends SsoException
{
}
