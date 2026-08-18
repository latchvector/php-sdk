<?php

declare(strict_types=1);

use LatchVector\Sso\Symfony\PermissionVoter;
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

    // Lets permission codes (and machine-token scopes) be used directly as
    // #[IsGranted(...)] attributes. Without it, non-ROLE_ codes abstain in
    // RoleVoter and the decision defaults to denied. See PermissionVoter.
    $configurator->services()
        ->set(PermissionVoter::class)
        ->tag('security.voter');
};
