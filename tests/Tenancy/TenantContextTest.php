<?php

declare(strict_types=1);

namespace LatchVector\Sso\Tests\Tenancy;

use LatchVector\Sso\Principal;
use LatchVector\Sso\Tenancy\TenantContext;
use PHPUnit\Framework\TestCase;

final class TenantContextTest extends TestCase
{
    public function testFreshContextIsActiveButHasNoTenant(): void
    {
        $context = new TenantContext();

        // Active by default, so an unconfigured read/write fails closed rather
        // than seeing everything.
        self::assertTrue($context->isActive());
        self::assertFalse($context->shouldScope());
        self::assertNull($context->tenantId());
    }

    public function testKnownTenantScopes(): void
    {
        $context = new TenantContext();
        $context->set(tenantId: 7);

        self::assertTrue($context->isActive());
        self::assertTrue($context->shouldScope());
        self::assertSame(7, $context->tenantId());
    }

    public function testBypassIsNotActive(): void
    {
        $context = new TenantContext();
        $context->set(tenantId: 7, bypass: true);

        self::assertFalse($context->isActive());
        self::assertFalse($context->shouldScope());
    }

    public function testDisabledIsNotActive(): void
    {
        $context = new TenantContext();
        $context->configure(false);
        $context->set(tenantId: 7);

        self::assertFalse($context->isActive());
    }

    public function testForgetResetsToFailClosed(): void
    {
        $context = new TenantContext();
        $context->set(tenantId: 7, subtreePaths: ['/7/']);
        $context->forget();

        self::assertTrue($context->isActive());
        self::assertNull($context->tenantId());
        self::assertFalse($context->hasOrgReach());
    }

    public function testFromPrincipalAdoptsTenantAndReach(): void
    {
        $context = new TenantContext();
        $context->fromPrincipal($this->principal(permissions: []));

        // Scopes to the USER's tenant + org subtree — the delegation case where a
        // machine backend acts on behalf of a forwarded user token.
        self::assertTrue($context->shouldScope());
        self::assertSame(7, $context->tenantId());
        self::assertSame(2, $context->ownOrgId());
        self::assertSame('/7/57/', $context->ownOrgPath());
        self::assertSame(['/7/57/'], $context->subtreePaths());
        self::assertTrue($context->hasOrgReach());
    }

    public function testFromPrincipalBypassOnlyWhenPermissionHeld(): void
    {
        $withPerm = new TenantContext();
        $withPerm->fromPrincipal($this->principal(permissions: ['PLATFORM_ADMIN']), ['PLATFORM_ADMIN']);
        self::assertFalse($withPerm->isActive()); // bypass caller → unconstrained

        $withoutPerm = new TenantContext();
        $withoutPerm->fromPrincipal($this->principal(permissions: ['invoice.read']), ['PLATFORM_ADMIN']);
        self::assertTrue($withoutPerm->isActive()); // still scoped

        $noList = new TenantContext();
        $noList->fromPrincipal($this->principal(permissions: ['PLATFORM_ADMIN'])); // no bypass list
        self::assertTrue($noList->isActive());
    }

    /** @param list<string> $permissions */
    private function principal(array $permissions): Principal
    {
        return new Principal(
            uid: 1,
            email: 'u@example.com',
            orgId: 2,
            tenantId: 7,
            orgPath: '/7/57/',
            permissions: $permissions,
            scopeSelf: [],
            scopeSubtree: ['/7/57/'],
            expiresAt: new \DateTimeImmutable('+1 hour'),
            claims: [],
        );
    }
}
