<?php

declare(strict_types=1);

namespace LatchVector\Sso\Tests\Laravel;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use LatchVector\Sso\Tenancy\TenantContext;
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
}
