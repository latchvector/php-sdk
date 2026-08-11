<?php

declare(strict_types=1);

namespace LatchVector\Sso\Laravel;

use Illuminate\Database\Eloquent\Builder;
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
}
