<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManagerInterface;
use LatchVector\Sso\Doctrine\TenantStampListener;
use LatchVector\Sso\Symfony\TenantFilterConfigurator;
use LatchVector\Sso\Tenancy\TenantContext;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $configurator): void {
    $services = $configurator->services();

    // Stamp new tenant-owned rows on insert.
    $services->set(TenantStampListener::class)
        ->args([service(TenantContext::class)])
        ->tag('doctrine.event_listener', ['event' => 'prePersist']);

    // Reset the context before the firewall (priority 250), re-wire it after
    // (priority 0) so the filter's cache-key discriminator reflects the tenant.
    $services->set(TenantFilterConfigurator::class)
        ->args([
            service(EntityManagerInterface::class),
            service(TenantContext::class),
            '%latch_vector_sso.tenant.filter_name%',
        ])
        ->tag('kernel.event_listener', ['event' => 'kernel.request', 'method' => 'reset', 'priority' => 250])
        ->tag('kernel.event_listener', ['event' => 'kernel.request', 'method' => 'configure', 'priority' => 0]);
};
