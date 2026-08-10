<?php

declare(strict_types=1);

namespace LatchVector\Sso\Tests\Symfony;

use Doctrine\ORM\EntityManager;
use LatchVector\Sso\Symfony\TenantFilterConfigurator;
use LatchVector\Sso\Tenancy\TenantContext;
use LatchVector\Sso\Tests\Doctrine\Fixtures\Invoice;
use LatchVector\Sso\Tests\Support\DoctrineEnv;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Exercises the request-lifecycle wiring the bundle installs: reset before auth,
 * configure after auth, and that both keep the Doctrine filter fail closed.
 */
final class TenantFilterConfiguratorTest extends TestCase
{
    private EntityManager $em;
    private TenantContext $context;
    private TenantFilterConfigurator $configurator;

    protected function setUp(): void
    {
        $this->context = new TenantContext();
        // Filter registered but NOT enabled — the configurator must enable it.
        $this->em = DoctrineEnv::create($this->context, enableFilter: false);
        $this->configurator = new TenantFilterConfigurator($this->em, $this->context, 'latchvector_tenant');

        // Seed two tenants (prePersist stamps from the context).
        $this->context->set(tenantId: 10);
        $this->em->persist(new Invoice('A1'));
        $this->em->flush();
        $this->context->set(tenantId: 20);
        $this->em->persist(new Invoice('B1'));
        $this->em->flush();
        $this->em->clear();
        $this->context->forget();
    }

    private function event(): RequestEvent
    {
        $kernel = new class implements HttpKernelInterface {
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
            {
                return new Response();
            }
        };

        return new RequestEvent($kernel, Request::create('/api/x'), HttpKernelInterface::MAIN_REQUEST);
    }

    /** @return list<Invoice> */
    private function invoices(): array
    {
        return $this->em->createQuery('select i from ' . Invoice::class . ' i')->getResult();
    }

    public function testConfigureEnablesAndScopesToTheAuthenticatedTenant(): void
    {
        // Simulate: authenticator set the tenant, then `configure` runs after it.
        $this->context->set(tenantId: 10);
        $this->configurator->configure($this->event());

        self::assertTrue($this->em->getFilters()->isEnabled('latchvector_tenant'));
        $rows = $this->invoices();
        self::assertCount(1, $rows);
        self::assertSame('A1', $rows[0]->number);
    }

    public function testResetLeavesItFailClosed(): void
    {
        // A leftover tenant from a previous request…
        $this->context->set(tenantId: 10);
        // …must be cleared by reset (which runs before authentication).
        $this->configurator->reset($this->event());

        self::assertTrue($this->em->getFilters()->isEnabled('latchvector_tenant'));
        self::assertSame([], $this->invoices(), 'reset must fail closed, not show a stale tenant');
    }

    public function testWorkerReuse_unauthenticatedSecondRequestSeesNothing(): void
    {
        // Request 1: authenticated as tenant 10.
        $this->configurator->reset($this->event());
        $this->context->set(tenantId: 10);
        $this->configurator->configure($this->event());
        self::assertCount(1, $this->invoices());

        // Request 2 on the SAME process (worker): reset runs, no authentication
        // happens, configure runs — tenant 10 must NOT linger.
        $this->configurator->reset($this->event());
        $this->configurator->configure($this->event());
        self::assertSame([], $this->invoices());
    }
}
