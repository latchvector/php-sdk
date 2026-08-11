<?php

declare(strict_types=1);

namespace LatchVector\Sso\Tests\Symfony;

use LatchVector\Sso\Doctrine\TenantFilter;
use LatchVector\Sso\Doctrine\TenantStampListener;
use LatchVector\Sso\Symfony\DependencyInjection\Configuration;
use LatchVector\Sso\Symfony\DependencyInjection\LatchVectorSsoExtension;
use LatchVector\Sso\Symfony\SsoAuthenticator;
use LatchVector\Sso\Symfony\TenantFilterConfigurator;
use LatchVector\Sso\Tenancy\TenantContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Proves the bundle's DI wiring: the services, tags and Doctrine filter
 * registration an app relies on after just enabling the bundle.
 */
final class BundleWiringTest extends TestCase
{
    private function load(array $config = []): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new LatchVectorSsoExtension())->load([$config], $container);

        return $container;
    }

    public function testRegistersTheTenantServices(): void
    {
        $container = $this->load();

        self::assertTrue($container->hasDefinition(TenantContext::class));
        self::assertTrue($container->hasDefinition(TenantStampListener::class));
        self::assertTrue($container->hasDefinition(TenantFilterConfigurator::class));
        self::assertTrue($container->hasDefinition(SsoAuthenticator::class));
    }

    public function testStampListenerIsTaggedForPrePersist(): void
    {
        $tags = $this->load()->getDefinition(TenantStampListener::class)->getTag('doctrine.event_listener');

        self::assertCount(1, $tags);
        self::assertSame('prePersist', $tags[0]['event']);
    }

    public function testConfiguratorRunsBeforeAndAfterTheFirewall(): void
    {
        $tags = $this->load()->getDefinition(TenantFilterConfigurator::class)->getTag('kernel.event_listener');
        $byMethod = [];
        foreach ($tags as $tag) {
            $byMethod[$tag['method']] = $tag['priority'];
        }

        // reset before the firewall (which runs at 8), configure after it.
        self::assertGreaterThan(8, $byMethod['reset']);
        self::assertLessThan(8, $byMethod['configure']);
    }

    public function testAuthenticatorReceivesTheBypassPermission(): void
    {
        $container = $this->load(['tenant' => ['bypass_permission' => 'platform.admin']]);

        self::assertSame('platform.admin', $container->getParameter('latch_vector_sso.tenant.bypass_permission'));
        // 3rd constructor arg of the authenticator is that parameter.
        $args = $container->getDefinition(SsoAuthenticator::class)->getArguments();
        self::assertSame('%latch_vector_sso.tenant.bypass_permission%', $args[2]);
    }

    public function testPrependRegistersTheDoctrineFilterWhenDoctrineIsPresent(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.bundles', ['DoctrineBundle' => 'X']);
        (new LatchVectorSsoExtension())->prepend($container);

        $configs = $container->getExtensionConfig('doctrine');
        $filters = $configs[0]['orm']['filters'] ?? [];
        self::assertArrayHasKey('latchvector_tenant', $filters);
        self::assertSame(TenantFilter::class, $filters['latchvector_tenant']['class']);
        self::assertTrue($filters['latchvector_tenant']['enabled']);
    }

    public function testPrependSkipsWhenDoctrineIsAbsent(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.bundles', []);
        (new LatchVectorSsoExtension())->prepend($container);

        self::assertSame([], $container->getExtensionConfig('doctrine'));
    }

    public function testConfigurationDefaultsBypassToNull(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), []);

        self::assertNull($config['tenant']['bypass_permission']);
    }
}
