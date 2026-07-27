<?php

declare(strict_types=1);

namespace LatchVector\Sso\Tenancy;

/**
 * The tenant, and the caller's reach within it, taken from the verified token.
 *
 * Set once per request by the auth middleware and read by the ORM scope:
 *
 *  - `tenant_id` is the hard wall between customers — every tenant-aware query
 *    is confined to it.
 *  - `scope_subtree` / `scope_self` are the caller's reach WITHIN the tenant,
 *    resolved from their roles at token-issue time. A model in `subtree` mode is
 *    additionally narrowed to exactly those org paths, so SELF sees one node and
 *    SUBTREE sees a node and everything below it — no more, so no leak.
 *
 * A caller holding a configured bypass permission is left unconstrained.
 */
final class TenantContext
{
    private ?int $tenantId = null;
    private bool $bypass = false;
    private bool $enabled = true;

    /** The caller's own node — used to stamp new rows in `subtree` mode. */
    private ?int $ownOrgId = null;
    private ?string $ownOrgPath = null;

    /** @var list<string> paths the caller reaches WITH everything below them */
    private array $subtreePaths = [];
    /** @var list<string> paths the caller reaches at that node only */
    private array $selfPaths = [];

    /** Turn scoping off — e.g. in a sandbox, so dev testing isn't confined. */
    public function configure(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    /**
     * @param list<string> $subtreePaths
     * @param list<string> $selfPaths
     */
    public function set(
        ?int $tenantId,
        bool $bypass = false,
        ?int $ownOrgId = null,
        ?string $ownOrgPath = null,
        array $subtreePaths = [],
        array $selfPaths = [],
    ): void {
        $this->tenantId = $tenantId;
        $this->bypass = $bypass;
        $this->ownOrgId = $ownOrgId;
        $this->ownOrgPath = $ownOrgPath;
        $this->subtreePaths = $subtreePaths;
        $this->selfPaths = $selfPaths;
    }

    public function forget(): void
    {
        $this->tenantId = null;
        $this->bypass = false;
        $this->ownOrgId = null;
        $this->ownOrgPath = null;
        $this->subtreePaths = [];
        $this->selfPaths = [];
    }

    public function tenantId(): ?int
    {
        return $this->tenantId;
    }

    public function ownOrgId(): ?int
    {
        return $this->ownOrgId;
    }

    public function ownOrgPath(): ?string
    {
        return $this->ownOrgPath;
    }

    /** @return list<string> */
    public function subtreePaths(): array
    {
        return $this->subtreePaths;
    }

    /** @return list<string> */
    public function selfPaths(): array
    {
        return $this->selfPaths;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /** Whether queries should be constrained to a tenant right now. */
    public function shouldScope(): bool
    {
        return $this->enabled && !$this->bypass && $this->tenantId !== null;
    }

    /**
     * Whether we know the caller's org reach — required to narrow a `subtree`
     * model. Absent for a machine token (no user scope claims); such callers
     * fall back to tenant-wide, which is still leak-safe across customers.
     */
    public function hasOrgReach(): bool
    {
        return $this->subtreePaths !== [] || $this->selfPaths !== [];
    }
}
