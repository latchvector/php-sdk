<?php

declare(strict_types=1);

namespace LatchVector\Sso\Exception;

/** Too many failed attempts. An administrator must unlock. */
class AccountLockedException extends SsoException
{
}
