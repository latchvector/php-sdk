<?php

declare(strict_types=1);

namespace LatchVector\Sso\Tests\Symfony\Fixtures;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use LatchVector\Sso\Symfony\LatchVectorSsoBundle;
use LatchVector\Sso\TokenVerifier;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;

/**
 * A real, minimal Symfony kernel that boots FrameworkBundle + DoctrineBundle +
 * our bundle together — the true end-to-end proof that the tenant boundary wires
 * up in a live container.
 */
final class TestKernel extends Kernel
{
    public function registerBundles(): iterable
    {
        return [new FrameworkBundle(), new DoctrineBundle(), new LatchVectorSsoBundle()];
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(function (ContainerBuilder $container): void {
            $container->loadFromExtension('framework', [
                'secret' => 'test',
                'test' => true,
                'http_method_override' => false,
                'handle_all_throwables' => true,
                'php_errors' => ['log' => false],
                'router' => ['utf8' => true, 'resource' => 'kernel::loadRoutes', 'type' => 'service'],
            ]);

            $container->loadFromExtension('doctrine', [
                'dbal' => ['driver' => 'pdo_sqlite', 'url' => 'sqlite:///:memory:'],
                'orm' => [
                    'enable_native_lazy_objects' => true, // PHP 8.4+ proxies, no var-exporter
                    'entity_managers' => [
                        'default' => [
                            'mappings' => [
                                'Test' => [
                                    'is_bundle' => false,
                                    'type' => 'attribute',
                                    'dir' => $this->getProjectDir() . '/tests/Doctrine/Fixtures',
                                    'prefix' => 'LatchVector\\Sso\\Tests\\Doctrine\\Fixtures',
                                ],
                            ],
                        ],
                    ],
                ],
            ]);

            // Stub TokenVerifier so the bundle's SsoAuthenticator can be built.
            $container->register(TokenVerifier::class)
                ->setArguments(['https://test/issuer', 'test-audience'])
                ->setPublic(true);
        });
    }

    public function loadRoutes(): \Symfony\Component\Routing\RouteCollection
    {
        return new \Symfony\Component\Routing\RouteCollection();
    }

    public function getProjectDir(): string
    {
        return dirname(__DIR__, 3); // sdk/php
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/lv_sso_kernel_cache_' . spl_object_id($this);
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/lv_sso_kernel_log';
    }
}
