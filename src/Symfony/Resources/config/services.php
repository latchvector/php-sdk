<?php

declare(strict_types=1);

use LatchVector\Sso\Tenancy\TenantContext;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $configurator): void {
    // The shared per-request tenant context. Public so a console command or
    // worker can deliberately opt out ($context->configure(false)) before doing
    // cross-tenant work. Registered even without Doctrine/Security so the value
    // object is always available.
    $configurator->services()
        ->set(TenantContext::class)->public();
};
