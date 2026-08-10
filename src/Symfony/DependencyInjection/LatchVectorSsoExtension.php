<?php

declare(strict_types=1);

namespace LatchVector\Sso\Symfony\DependencyInjection;

use LatchVector\Sso\Doctrine\TenantFilter;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

final class LatchVectorSsoExtension extends Extension implements PrependExtensionInterface
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);

        $container->setParameter('latch_vector_sso.tenant.bypass_permission', $config['tenant']['bypass_permission']);
        $container->setParameter('latch_vector_sso.tenant.filter_name', 'latchvector_tenant');

        $loader = new PhpFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));

        // Always available: the tenant context value object.
        $loader->load('services.php');

        // The Doctrine boundary only makes sense with Doctrine ORM installed —
        // otherwise these services reference a non-existent EntityManager and the
        // container would fail to build. Degrade gracefully instead.
        if (interface_exists(\Doctrine\ORM\EntityManagerInterface::class)) {
            $loader->load('doctrine.php');
        }

        // The authenticator extends a Symfony Security class; only register it
        // when Security is installed (an app may use the SDK for token
        // verification only, or for Doctrine tenancy without the firewall).
        if (class_exists(\Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator::class)) {
            $loader->load('security.php');
        }
    }

    /**
     * Register the tenant filter with Doctrine and enable it for the whole
     * EntityManager. Enabled-by-default is deliberate: an enabled filter that
     * has no tenant fails CLOSED, whereas a filter that is only turned on "after
     * login" is fail-open for anything that runs earlier.
     */
    public function prepend(ContainerBuilder $container): void
    {
        $bundles = $container->getParameter('kernel.bundles');
        if (!is_array($bundles) || !isset($bundles['DoctrineBundle'])) {
            return;
        }

        $container->prependExtensionConfig('doctrine', [
            'orm' => [
                'filters' => [
                    'latchvector_tenant' => [
                        'class' => TenantFilter::class,
                        'enabled' => true,
                    ],
                ],
            ],
        ]);
    }

    public function getAlias(): string
    {
        return 'latch_vector_sso';
    }
}
