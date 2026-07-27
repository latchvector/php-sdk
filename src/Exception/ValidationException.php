<?php

declare(strict_types=1);

namespace LatchVector\Sso\Exception;

/** Malformed request. `$fields` names what failed validation. */
class ValidationException extends SsoException
{
    /** @param array<string, string> $fields */
    public function __construct(string $message, public readonly array $fields = [])
    {
        parent::__construct('validation_failed', 400, $message);
    }
}
