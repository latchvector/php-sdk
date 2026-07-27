<?php

declare(strict_types=1);

namespace LatchVector\Sso\Exception;

/** Misconfiguration, raised at construction time where possible. */
class ConfigurationException extends SsoException
{
    public function __construct(string $message)
    {
        parent::__construct('configuration_error', 500, $message);
    }
}
