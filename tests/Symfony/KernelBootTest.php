<?php

declare(strict_types=1);

namespace LatchVector\Sso\Tests\Symfony;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use LatchVector\Sso\Symfony\TenantFilterConfigurator;
use LatchVector\Sso\Tenancy\TenantContext;
use LatchVector\Sso\Tests\Doctrine\Fixtures\Invoice;
use LatchVector\Sso\Tests\Symfony\Fixtures\TestKernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Boots a real Symfony kernel (FrameworkBundle + DoctrineBundle + our bundle) and
 * proves the tenant boundary is live end-to-end: the filter is registered and
 * enabled by the bundle's Doctrine prepend, and a query through the real
 * container's EntityManager is scoped to the caller's tenant.
 */
final class KernelBootTest extends TestCase
{
    private TestKernel $kernel;

    protected function setUp(): void
    {
        $this->kernel = new TestKernel('test', true);
        $this->kernel->boot();
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
        (new Filesystem())->remove($this->kernel->getCacheDir());
    }

    private function container(): \Psr\Container\ContainerInterface
    {
        return $this->kernel->getContainer()->get('test.service_container');
    }

    public function testBundlesBootAndTheTenantFilterIsRegisteredAndEnabled(): void
    {
        $em = $this->container()->get(EntityManagerInterface::class);

        // Registered + enabled purely by enabling the bundle (its Doctrine
        // prepend), with no app configuration.
        self::assertTrue($em->getFilters()->has('latchvector_tenant'));
        self::assertTrue($em->getFilters()->isEnabled('latchvector_tenant'));
    }

    public function testQueryIsScopedToTheTenantThroughTheRealContainer(): void
    {
        $c = $this->container();
        /** @var EntityManagerInterface $em */
        $em = $c->get(EntityManagerInterface::class);
        /** @var TenantContext $context */
        $context = $c->get(TenantContext::class);
        /** @var TenantFilterConfigurator $configurator */
        $configurator = $c->get(TenantFilterConfigurator::class);

        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());

        // Seed two tenants.
        $context->set(tenantId: 10);
        $em->persist(new Invoice('A1'));
        $em->flush();
        $context->set(tenantId: 20);
        $em->persist(new Invoice('B1'));
        $em->flush();
        $em->clear();

        // Simulate the authenticated request: tenant set, then `configure` wires
        // the filter (exactly what the bundle's listener does).
        $context->set(tenantId: 10);
        $configurator->configure($this->requestEvent());

        $rows = $em->createQuery('select i from ' . Invoice::class . ' i')->getResult();
        self::assertCount(1, $rows);
        self::assertSame('A1', $rows[0]->number);
    }

    private function requestEvent(): RequestEvent
    {
        $httpKernel = new class implements HttpKernelInterface {
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
            {
                return new Response();
            }
        };

        return new RequestEvent($httpKernel, Request::create('/api/x'), HttpKernelInterface::MAIN_REQUEST);
    }
}
