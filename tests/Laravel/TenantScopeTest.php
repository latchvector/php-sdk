<?php

declare(strict_types=1);

namespace LatchVector\Sso\Tests\Laravel;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use LatchVector\Sso\Tenancy\OrgReachException;
use LatchVector\Sso\Tenancy\TenantContext;
use LatchVector\Sso\Tests\Laravel\Fixtures\Record;
use LatchVector\Sso\Tests\Laravel\Fixtures\Widget;
use PHPUnit\Framework\TestCase;

/**
 * Runs the Laravel BelongsToTenant scope against a real Eloquent + SQLite, to
 * prove the same fail-closed rules as the Doctrine side.
 */
final class TenantScopeTest extends TestCase
{
    private TenantContext $context;

    protected function setUp(): void
    {
        $this->context = new TenantContext();

        $container = new Container();
        Container::setInstance($container); // so the trait's app()/config() resolve
        $container->instance(TenantContext::class, $this->context);
        $container->instance('config', new Repository([
            'latchvector-sso' => ['tenant' => ['column' => 'tenant_id']],
        ]));

        $capsule = new Capsule($container);
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $dispatcher = new Dispatcher($container);
        $capsule->setEventDispatcher($dispatcher);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        // Model lifecycle events (the `creating` stamp hook) need the dispatcher
        // on the base Model — set it explicitly so it fires standalone.
        \Illuminate\Database\Eloquent\Model::setEventDispatcher($dispatcher);
        // Eloquent boots a model once per process and caches it statically; force
        // a re-boot so this test's fresh dispatcher gets the `creating` listener.
        \Illuminate\Database\Eloquent\Model::clearBootedModels();

        Capsule::schema()->create('widgets', function ($table): void {
            $table->increments('id');
            $table->string('name');
            $table->unsignedBigInteger('tenant_id')->nullable();
        });

        // A table using the DEFAULT (subtree) scope — carries the org columns.
        Capsule::schema()->create('records', function ($table): void {
            $table->increments('id');
            $table->string('name');
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->unsignedBigInteger('org_id')->nullable();
            $table->string('org_path')->nullable();
        });

        // Seed two tenants with scoping deliberately off.
        $this->context->configure(false);
        Widget::query()->insert([
            ['name' => 'a', 'tenant_id' => 10],
            ['name' => 'b', 'tenant_id' => 20],
        ]);
        $this->context->configure(true);
    }

    protected function tearDown(): void
    {
        Capsule::schema()->drop('widgets');
        Capsule::schema()->drop('records');
        Container::setInstance(null);
    }

    public function testScopedToTheTenant(): void
    {
        $this->context->set(tenantId: 10);
        $rows = Widget::all();

        self::assertCount(1, $rows);
        self::assertSame('a', $rows[0]->name);
    }

    public function testFailClosedActiveButNoTenantSeesNothing(): void
    {
        $this->context->set(tenantId: null); // active, no tenant
        self::assertSame(0, Widget::query()->count());
    }

    public function testDisabledSeesEveryTenant(): void
    {
        $this->context->configure(false);
        self::assertSame(2, Widget::query()->count());
    }

    public function testBypassSeesEveryTenant(): void
    {
        $this->context->set(tenantId: 10, bypass: true);
        self::assertSame(2, Widget::query()->count());
    }

    public function testCreateStampsTheTenant(): void
    {
        $this->context->set(tenantId: 77);
        $w = Widget::create(['name' => 'fresh']);

        self::assertSame(77, (int) $w->tenant_id);
    }

    public function testCreateWithoutTenantThrows(): void
    {
        $this->context->set(tenantId: null); // active, no tenant
        $this->expectException(\RuntimeException::class);
        Widget::create(['name' => 'nope']);
    }

    public function testDefaultScopeIsolatesSiblingOrgs(): void
    {
        // Seed three org nodes in tenant 10, scoping off.
        $this->context->configure(false);
        Record::query()->insert([
            ['name' => 'root', 'tenant_id' => 10, 'org_id' => 1, 'org_path' => '/10/'],
            ['name' => 'a', 'tenant_id' => 10, 'org_id' => 2, 'org_path' => '/10/57/'],
            ['name' => 'a-child', 'tenant_id' => 10, 'org_id' => 3, 'org_path' => '/10/57/9/'],
            ['name' => 'b', 'tenant_id' => 10, 'org_id' => 4, 'org_path' => '/10/58/'],
        ]);
        $this->context->configure(true);

        // A SUBTREE grant at /10/57/ sees that node and below — never sibling B.
        $this->context->set(tenantId: 10, subtreePaths: ['/10/57/']);
        $names = Record::query()->pluck('name')->all();
        sort($names);
        self::assertSame(['a', 'a-child'], $names);

        // A SELF grant at /10/57/ sees exactly that node.
        $this->context->set(tenantId: 10, selfPaths: ['/10/57/']);
        self::assertSame(['a'], Record::query()->pluck('name')->all());
    }

    public function testDefaultScopeStampsCallersOwnNode(): void
    {
        $this->context->set(
            tenantId: 10,
            ownOrgId: 2,
            ownOrgPath: '/10/57/',
            subtreePaths: ['/10/57/'],
        );
        $r = Record::create(['name' => 'fresh']);

        self::assertSame(10, (int) $r->tenant_id);
        self::assertSame(2, (int) $r->org_id);
        self::assertSame('/10/57/', $r->org_path);
    }

    public function testForOrgFiltersWithinReach(): void
    {
        $this->seedRecords();
        // Caller reaches the whole /10/ subtree.
        $this->context->set(tenantId: 10, subtreePaths: ['/10/']);

        // A specific child, node only.
        self::assertSame(['a'], Record::forOrg('/10/57/')->pluck('name')->all());

        // The same child WITH its descendants.
        $names = Record::forOrg('/10/57/', true)->pluck('name')->all();
        sort($names);
        self::assertSame(['a', 'a-child'], $names);

        // A sibling — the other branch, node only.
        self::assertSame(['b'], Record::forOrg('/10/58/')->pluck('name')->all());
    }

    public function testForOrgAcceptsPathWithoutTrailingSlash(): void
    {
        $this->seedRecords();
        $this->context->set(tenantId: 10, subtreePaths: ['/10/']);

        self::assertSame(['a'], Record::forOrg('/10/57')->pluck('name')->all());
    }

    public function testForOrgThrowsOutsideReach(): void
    {
        $this->seedRecords();
        // Caller only reaches /10/57/ and below — not the sibling /10/58/.
        $this->context->set(tenantId: 10, subtreePaths: ['/10/57/']);

        $this->expectException(OrgReachException::class);
        Record::forOrg('/10/58/')->get();
    }

    public function testForOrgSubtreeRefusedForSelfOnlyGrant(): void
    {
        $this->seedRecords();
        // SELF grant at the node authorises the node, not its descendants.
        $this->context->set(tenantId: 10, selfPaths: ['/10/57/']);

        Record::forOrg('/10/57/')->get(); // node view is fine
        $this->expectException(OrgReachException::class);
        Record::forOrg('/10/57/', true)->get();
    }

    public function testForOrgIdIsSafeByIntersection(): void
    {
        $this->seedRecords();
        // Caller reaches only /10/57/; org_id 4 lives at the sibling /10/58/.
        $this->context->set(tenantId: 10, subtreePaths: ['/10/57/']);

        self::assertSame(['a'], Record::forOrgId(2)->pluck('name')->all()); // in reach
        self::assertSame([], Record::forOrgId(4)->pluck('name')->all());    // out of reach → empty
    }

    private function seedRecords(): void
    {
        $this->context->configure(false);
        Record::query()->insert([
            ['name' => 'root', 'tenant_id' => 10, 'org_id' => 1, 'org_path' => '/10/'],
            ['name' => 'a', 'tenant_id' => 10, 'org_id' => 2, 'org_path' => '/10/57/'],
            ['name' => 'a-child', 'tenant_id' => 10, 'org_id' => 3, 'org_path' => '/10/57/9/'],
            ['name' => 'b', 'tenant_id' => 10, 'org_id' => 4, 'org_path' => '/10/58/'],
        ]);
        $this->context->configure(true);
    }
}
