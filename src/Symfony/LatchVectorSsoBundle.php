<?php

declare(strict_types=1);

namespace LatchVector\Sso\Symfony;

use LatchVector\Sso\Symfony\DependencyInjection\LatchVectorSsoExtension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Wires the Latch Vector SSO tenant boundary into a Symfony app:
 *
 *  - registers the Doctrine tenant filter (ENABLED for the whole EntityManager),
 *  - registers the prePersist stamp listener,
 *  - resets + wires the shared {@see \LatchVector\Sso\Tenancy\TenantContext} onto
 *    the filter at the start of every request,
 *  - registers the {@see SsoAuthenticator} so it publishes the tenant/reach.
 *
 * Enable it in config/bundles.php:
 *
 *     LatchVector\Sso\Symfony\LatchVectorSsoBundle::class => ['all' => true],
 */
final class LatchVectorSsoBundle extends Bundle
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new LatchVectorSsoExtension();
    }
}
