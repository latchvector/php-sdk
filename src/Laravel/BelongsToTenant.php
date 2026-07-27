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
 * By default a model is scoped to the **tenant** — the hard wall between
 * customers. To also confine it to the caller's reach WITHIN the tenant (so a
 * department sees only its own branch), declare `subtree` mode and give the
 * table an `org_path` column:
 *
 *   class Patient extends Model
 *   {
 *       use \LatchVector\Sso\Laravel\BelongsToTenant;
 *       protected string $tenantScope = 'subtree';
 *   }
 *
 * What is visible in `subtree` mode is decided entirely by the token: a SELF
 * grant sees exactly that node, a SUBTREE grant sees that node and everything
 * below it. A caller with a bypass permission sees across tenants. Reach across
 * the scope deliberately with {@see allTenants()}.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope());

        static::creating(function ($model): void {
            /** @var TenantContext $context */
            $context = app(TenantContext::class);
            if (!$context->shouldScope()) {
                return;
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

    /** `tenant` (default) or `subtree`. Override with `protected $tenantScope`. */
    public function getTenantScopeMode(): string
    {
        return property_exists($this, 'tenantScope') ? $this->tenantScope : 'tenant';
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
