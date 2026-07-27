<?php

declare(strict_types=1);

namespace LatchVector\Sso\Exception;

/** Account disabled or erased. Send the user to log in; do not retry. */
class AccountNotActiveException extends SsoException
{
}
