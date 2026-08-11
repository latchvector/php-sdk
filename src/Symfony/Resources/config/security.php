<?php

declare(strict_types=1);

use LatchVector\Sso\Symfony\SsoAuthenticator;
use LatchVector\Sso\Tenancy\TenantContext;
use LatchVector\Sso\TokenVerifier;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $configurator): void {
    // Pre-wired to publish the tenant + reach. The app still defines TokenVerifier
    // (it needs the issuer/audience env), then lists this class under
    // security.firewalls.<fw>.custom_authenticators.
    $configurator->services()
        ->set(SsoAuthenticator::class)
        ->args([
            service(TokenVerifier::class),
            service(TenantContext::class),
            '%latch_vector_sso.tenant.bypass_permission%',
        ]);
};
