<?php

declare(strict_types=1);

namespace LatchVector\Sso\Laravel;

use Illuminate\Database\Eloquent\Builder;
use LatchVector\Sso\Tenancy\OrgPath;
use LatchVector\Sso\Tenancy\OrgReachException;
use LatchVector\Sso\Tenancy\TenantContext;

/**
 * Makes an Eloquent model tenant-aware: every query is confined to the current
 * tenant, and a new row is stamped with it automatically.
 *
 *   class Invoice extends Model
 *   {
 *       use \LatchVector\Sso\Laravel\BelongsToTenant;
 *   }
 *
 * By default a model is scoped to the caller's org **subtree** — sibling orgs
 * within the same tenant never see each other's rows. Such a table needs an
 * `org_path` column. What is visible is decided entirely by the token: a SELF
 * grant sees exactly that node, a SUBTREE grant sees that node and everything
 * below it.
 *
 * For genuinely tenant-wide data (visible to every org under the tenant), opt
 * out deliberately:
 *
 *   class TenantSetting extends Model
 *   {
 *       use \LatchVector\Sso\Laravel\BelongsToTenant;
 *       protected string $tenantScope = 'tenant';
 *   }
 *
 * A caller with a bypass permission sees across tenants. Reach across the scope
 * deliberately with {@see allTenants()}.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope());

        static::creating(function ($model): void {
            /** @var TenantContext $context */
            $context = app(TenantContext::class);

            // Scoping deliberately off (sandbox/dev) or a bypass caller: leave the
            // row as-is (the caller is responsible for the tenant column).
            if (!$context->isActive()) {
                return;
            }

            // Fail closed on writes too: refuse to insert a tenant-owned row when
            // scoping is on but no tenant is known — inserting it unscoped (or
            // with a NULL tenant) is exactly the accidental leak we must prevent.
            if ($context->tenantId() === null) {
                throw new \RuntimeException(
                    'Cannot persist ' . get_class($model) . ': tenant scoping is active but no tenant '
                    . 'is set. Authenticate first, or disable scoping deliberately for this operation.',
                );
            }

            $tenantColumn = $model->getTenantColumn();
            if (empty($model->{$tenantColumn})) {
                $model->{$tenantColumn} = $context->tenantId();
            }

            // A subtree-scoped row belongs to the caller's own node; stamp its
            // org id and path so later reads can place it in the tree.
            if ($model->getTenantScopeMode() === 'subtree') {
                if (empty($model->org_id) && $context->ownOrgId() !== null) {
                    $model->org_id = $context->ownOrgId();
                }
                $pathColumn = $model->getOrgPathColumn();
                if (empty($model->{$pathColumn}) && $context->ownOrgPath() !== null) {
                    $model->{$pathColumn} = $context->ownOrgPath();
                }
            }
        });
    }

    public function getTenantColumn(): string
    {
        return (string) config('latchvector-sso.tenant.column', 'tenant_id');
    }

    public function getQualifiedTenantColumn(): string
    {
        return $this->getTable() . '.' . $this->getTenantColumn();
    }

    /**
     * `subtree` (the default) or `tenant`. Override with `protected $tenantScope`.
     *
     * The default is `subtree` so tenant data is org-tree isolated out of the box
     * — sibling orgs never see each other's rows. Such a model needs an `org_path`
     * column; without one a scoped read fails loudly rather than leaking. Opt a
     * table into whole-tenant visibility deliberately with
     * `protected string $tenantScope = 'tenant'`.
     */
    public function getTenantScopeMode(): string
    {
        return property_exists($this, 'tenantScope') ? $this->tenantScope : 'subtree';
    }

    public function getOrgPathColumn(): string
    {
        return property_exists($this, 'orgPathColumn') ? $this->orgPathColumn : 'org_path';
    }

    public function getQualifiedOrgPathColumn(): string
    {
        return $this->getTable() . '.' . $this->getOrgPathColumn();
    }

    /** Query every tenant's rows, ignoring the scope. Use deliberately. */
    public static function allTenants(): Builder
    {
        return static::withoutGlobalScope(TenantScope::class);
    }

    /**
     * Narrow to a chosen org WITHIN the caller's reach — the "show me only /2/5/"
     * case. This ANDs onto the always-on tenant scope, so it can only ever shrink
     * the result, never widen it, and it refuses a branch the token can't see.
     *
     *   Post::forOrg('/2/5/')->get();          // exactly that node
     *   Post::forOrg('/2/5/', true)->get();     // that node and everything below
     *
     * Default is the node alone (SELF). `$includeSubtree = true` also returns its
     * descendants and requires a SUBTREE grant over the node — otherwise, and for
     * any org outside reach, it throws {@see OrgReachException} (map to HTTP 403).
     * A bypass caller or a machine token (tenant-wide) may target any org in the
     * tenant.
     */
    public function scopeForOrg(Builder $query, string $orgPath, bool $includeSubtree = false): Builder
    {
        $path = OrgPath::normalize($orgPath);

        /** @var TenantContext $context */
        $context = app(TenantContext::class);
        $this->assertOrgReach($context, $path, $includeSubtree);

        $column = $this->getQualifiedOrgPathColumn();
        if ($includeSubtree) {
            return $query->where($column, 'like', OrgPath::escapeLike($path) . '%');
        }

        return $query->where($column, '=', $path);
    }

    /**
     * Narrow to a single org node by id — the "filter by the org id from a
     * dropdown" case. ANDs onto the always-on tenant scope, so it can only ever
     * shrink the result, never widen it.
     *
     *   Post::forOrgId(5)->get();               // rows whose org_id is 5
     *
     * Deliberately asymmetric with {@see scopeForOrg}, and worth understanding:
     *
     *  - An id **outside the caller's reach yields an empty result, NOT a 403.**
     *    A SELF caller asking for a child, a sibling org, another tenant's id —
     *    all come back empty, because the row simply falls outside the tenant +
     *    subtree wall the global scope already applies. No data leaks; the caller
     *    just cannot tell "no rows here" apart from "not allowed to see this org".
     *  - It cannot fail closed with a 403 the way the path method does, because
     *    the token carries the caller's reach as PATHS, not an id→path map. Given
     *    a bare id there is nothing local to validate against, and this method
     *    never calls the SSO to find out — an id filter must stay a pure query.
     *
     * When you DO want a 403 on a tampered / out-of-reach id (e.g. the id came
     * from the request, not from a list you already scoped), resolve it to a path
     * in your OWN org table — no round-trip to the SSO — and use the path method,
     * which fails closed:
     *
     *   $path = Org::findOrFail($orgId)->path;  // your local id→path lookup
     *   Post::forOrg($path)->get();             // throws (→ 403) if out of reach
     */
    public function scopeForOrgId(Builder $query, int $orgId): Builder
    {
        return $query->where($this->getTable() . '.org_id', '=', $orgId);
    }

    private function assertOrgReach(TenantContext $context, string $path, bool $includeSubtree): void
    {
        try {
            $context->assertReach($path, $includeSubtree);
        } catch (OrgReachException $e) {
            // In a real Laravel app raise the framework's own exception so the
            // handler renders 403 automatically; standalone, let ours propagate.
            if (class_exists(\Illuminate\Auth\Access\AuthorizationException::class)) {
                throw new \Illuminate\Auth\Access\AuthorizationException($e->getMessage(), previous: $e);
            }
            throw $e;
        }
    }
}
