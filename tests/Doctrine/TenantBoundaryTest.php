<?php

declare(strict_types=1);

namespace LatchVector\Sso\Tests\Doctrine;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use LatchVector\Sso\Doctrine\TenantFilter;
use LatchVector\Sso\Doctrine\TenantStampListener;
use LatchVector\Sso\Tenancy\TenantContext;
use LatchVector\Sso\Tests\Doctrine\Fixtures\Invoice;
use LatchVector\Sso\Tests\Doctrine\Fixtures\Patient;
use PHPUnit\Framework\TestCase;

final class TenantBoundaryTest extends TestCase
{
    private EntityManager $em;
    private TenantContext $context;

    protected function setUp(): void
    {
        $this->context = new TenantContext();

        $config = ORMSetup::createAttributeMetadataConfiguration([__DIR__ . '/Fixtures'], true);
        $config->enableNativeLazyObjects(true); // PHP 8.4+ proxies, no symfony/var-exporter
        $config->addFilter('latchvector_tenant', TenantFilter::class);

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);

        $eventManager = new EventManager();
        $eventManager->addEventListener([Events::prePersist], new TenantStampListener($this->context));

        $this->em = new EntityManager($connection, $config, $eventManager);

        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());

        // The filter is enabled for the whole EM (as the bundle does), and wired
        // to our shared context.
        $this->em->getFilters()->enable('latchvector_tenant');
        $this->refresh();
    }

    /**
     * Re-wire the filter to the (possibly just-changed) context — this is exactly
     * what the bundle's `configure` listener does after authentication, and it
     * refreshes the query-cache discriminator.
     */
    private function refresh(): void
    {
        $filter = $this->em->getFilters()->getFilter('latchvector_tenant');
        self::assertInstanceOf(TenantFilter::class, $filter);
        $filter->setContext($this->context);
    }

    /** @return list<Invoice> */
    private function invoices(): array
    {
        $this->refresh();

        return $this->em->createQuery('select i from ' . Invoice::class . ' i')->getResult();
    }

    /** Seed one invoice for tenant 10 and one for tenant 20, then clear the EM. */
    private function seedTwoTenants(): void
    {
        $this->context->set(tenantId: 10);
        $this->em->persist(new Invoice('A1'));
        $this->em->flush();

        $this->context->set(tenantId: 20);
        $this->em->persist(new Invoice('B1'));
        $this->em->flush();

        // A fresh EM per request has an empty identity map; simulate that so
        // find()/queries actually hit the DB (and the filter).
        $this->em->clear();
    }

    public function testDqlIsScopedToTheTenant(): void
    {
        $this->seedTwoTenants();

        $this->context->set(tenantId: 10);
        $rows = $this->invoices();

        self::assertCount(1, $rows);
        self::assertSame('A1', $rows[0]->number);
        self::assertSame(10, $rows[0]->getTenantId());
    }

    public function testFindByAnotherTenantsIdReturnsNull(): void
    {
        $this->context->set(tenantId: 20);
        $this->em->persist($b = new Invoice('B1'));
        $this->em->flush();
        $otherId = $b->getId();
        $this->em->clear();

        $this->context->set(tenantId: 10);
        $this->refresh();
        self::assertNull($this->em->find(Invoice::class, $otherId));
    }

    public function testWriteWithoutTenantThrows(): void
    {
        $this->context->set(tenantId: null); // active, but no tenant known
        $this->expectException(\RuntimeException::class);
        $this->em->persist(new Invoice('X')); // prePersist fires here and fails closed
    }

    public function testInsertStampsTheTenant(): void
    {
        $this->context->set(tenantId: 42);
        $this->em->persist($inv = new Invoice('Z'));
        $this->em->flush();

        self::assertSame(42, $inv->getTenantId());
    }

    public function testFailClosedActiveButNoTenantSeesNothing(): void
    {
        $this->seedTwoTenants();

        $this->context->set(tenantId: null); // active (default), no tenant → deny
        self::assertSame([], $this->invoices());
    }

    public function testDisabledScopingSeesEveryTenant(): void
    {
        $this->seedTwoTenants();

        $this->context->configure(false); // deliberate opt-out (e.g. a CLI job)
        self::assertCount(2, $this->invoices());
    }

    public function testBypassCallerSeesEveryTenant(): void
    {
        $this->seedTwoTenants();

        $this->context->set(tenantId: 10, bypass: true); // e.g. a platform operator
        self::assertCount(2, $this->invoices());
    }

    public function testQueryCacheIsNotSharedAcrossTenants(): void
    {
        $this->seedTwoTenants();

        $this->context->set(tenantId: 10);
        $a = $this->invoices();

        // Same DQL, different tenant: must NOT reuse tenant 10's compiled query.
        $this->context->set(tenantId: 20);
        $b = $this->invoices();

        self::assertCount(1, $a);
        self::assertSame('A1', $a[0]->number);
        self::assertCount(1, $b);
        self::assertSame('B1', $b[0]->number);
    }

    public function testSubtreeGrantSeesNodeAndBelow_SelfGrantSeesOnlyNode(): void
    {
        $this->context->set(tenantId: 10); // all in tenant 10, different org nodes
        foreach (['/10/', '/10/57/', '/10/57/9/'] as $i => $path) {
            $p = new Patient('p' . $i);
            $p->setOrgPath($path);
            $p->setOrgId(100 + $i);
            $this->em->persist($p);
        }
        $this->em->flush();
        $this->em->clear();

        // SUBTREE grant at /10/57/ → that node and everything below (2 rows).
        $this->context->set(tenantId: 10, subtreePaths: ['/10/57/']);
        $this->refresh();
        $subtree = $this->em->createQuery('select p from ' . Patient::class . ' p')->getResult();
        self::assertCount(2, $subtree);

        // SELF grant at /10/57/ → that exact node only (1 row).
        $this->context->set(tenantId: 10, selfPaths: ['/10/57/']);
        $this->refresh();
        $self = $this->em->createQuery('select p from ' . Patient::class . ' p')->getResult();
        self::assertCount(1, $self);
        self::assertSame('/10/57/', $self[0]->getOrgPath());
    }
}
