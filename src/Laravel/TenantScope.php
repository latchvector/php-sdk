<?php

declare(strict_types=1);

namespace LatchVector\Sso\Laravel;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use LatchVector\Sso\Tenancy\TenantContext;

/**
 * Global scope that confines a model's queries to the current tenant, and — for
 * a model in `subtree` mode — to the caller's reach within it.
 *
 * The tenant clause is a plain equality, index-friendly against a
 * `(tenant_id, …)` composite index. The subtree clause is a set of
 * left-anchored prefix matches (SUBTREE grants) plus exact matches (SELF
 * grants), index-friendly against a `(tenant_id, org_path text_pattern_ops)`
 * index. Nothing here produces a scan.
 */
final class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        /** @var TenantContext $context */
        $context = app(TenantContext::class);
        if (!$context->shouldScope()) {
            return;
        }

        // The hard wall: same tenant, always.
        /** @phpstan-ignore-next-line — the trait guarantees these methods */
        $builder->where($model->getQualifiedTenantColumn(), '=', $context->tenantId());

        // Finer wall, opt-in: a subtree model is narrowed to exactly the org
        // paths the token grants. A machine token has no org reach, so it falls
        // back to tenant-wide — still leak-safe between customers.
        if ($model->getTenantScopeMode() !== 'subtree' || !$context->hasOrgReach()) {
            return;
        }

        $pathColumn = $model->getQualifiedOrgPathColumn();
        $subtreePaths = $context->subtreePaths();
        $selfPaths = $context->selfPaths();

        $builder->where(function (Builder $q) use ($pathColumn, $subtreePaths, $selfPaths): void {
            foreach ($subtreePaths as $prefix) {
                // A SUBTREE grant at /1/57/ covers /1/57/ and everything below.
                // The paths carry a trailing slash, so the prefix cannot leak
                // into a sibling (/1/5/ never matches /1/57/).
                $q->orWhere($pathColumn, 'like', self::escapeLike($prefix) . '%');
            }
            foreach ($selfPaths as $exact) {
                // A SELF grant is that node ONLY — an exact match, never below.
                $q->orWhere($pathColumn, '=', $exact);
            }
        });
    }

    /** Escape LIKE metacharacters so a path segment can't act as a wildcard. */
    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
