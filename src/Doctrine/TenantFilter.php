<?php

declare(strict_types=1);

namespace LatchVector\Sso\Doctrine;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;
use LatchVector\Sso\Tenancy\TenantContext;

/**
 * Confines every query on a {@see TenantAware} entity to the current tenant —
 * and, for an {@see OrgSubtreeAware} entity, to the caller's reach within it.
 * This is the Doctrine equivalent of the Laravel global scope.
 *
 * Design notes that make it SAFE (read before changing):
 *
 *  1. FAIL CLOSED. The filter is meant to be registered ENABLED for the whole
 *     EntityManager (the bundle does this). If scoping is active but no tenant is
 *     known — an unauthenticated path, a job that forgot to set context, or the
 *     context was never wired — it emits `1 = 0` (no rows), never an
 *     unconstrained query. Only an explicit opt-out ({@see TenantContext::configure}
 *     off, or a bypass caller) removes the constraint.
 *
 *  2. QUERY-CACHE SAFE. Doctrine keys its DQL→SQL cache on each enabled filter's
 *     serialized PARAMETERS (SQLFilter::__toString is final, so we can't encode
 *     state there). If nothing tenant-specific were a parameter, a compiled query
 *     could be reused across tenants — a cross-tenant leak. So {@see setContext}
 *     also pushes a discriminator parameter that captures the tenant + reach; the
 *     hash then differs per tenant. (setParameter also marks the filter dirty, so
 *     the hash is recomputed.)
 *
 * Call {@see setContext} AFTER the context is populated (the bundle wires it once
 * the request is authenticated), so the discriminator matches what the SQL uses.
 */
final class TenantFilter extends SQLFilter
{
    private ?TenantContext $context = null;

    public function setContext(TenantContext $context): self
    {
        $this->context = $context;
        // Make the tenant part of Doctrine's query-cache key (see design note 2).
        $this->setParameter('lv_tenant_discriminator', $this->discriminatorFor($context), 'string');

        return $this;
    }

    public function addFilterConstraint(ClassMetadata $targetEntity, $targetTableAlias): string
    {
        $class = $targetEntity->getName();
        if (!is_a($class, TenantAware::class, true)) {
            return '';
        }

        $ctx = $this->context;

        // Explicit opt-out: scoping deliberately off, or a bypass caller. Only a
        // configured context can opt out; a missing context never does.
        if ($ctx !== null && !$ctx->isActive()) {
            return '';
        }

        // Every tenant entity must declare HOW it is isolated. Org-tree isolation
        // is the default for tenant data (so sibling orgs can't see each other);
        // an entity that opts out must say so with TenantWide. One that declares
        // neither is a programming error — fail loud, never leak the whole tenant.
        $subtree = is_a($class, OrgSubtreeAware::class, true);
        if (!$subtree && !is_a($class, TenantWide::class, true)) {
            throw new \LogicException(sprintf(
                '%s is tenant-scoped but declares no isolation mode. Implement '
                . 'OrgSubtreeAware (org-tree isolation — the default for tenant data) '
                . 'or TenantWide (deliberately visible to the whole tenant).',
                $class,
            ));
        }

        // Fail closed: active (or unconfigured) but no known tenant → no rows.
        $tenantId = $ctx?->tenantId();
        if ($tenantId === null) {
            return '1 = 0';
        }

        $sql = sprintf('%s.tenant_id = %d', $targetTableAlias, $tenantId);

        // Subtree narrowing, only for OrgSubtreeAware entities and when the token
        // carries org reach (a machine token has none → tenant-wide, still safe).
        if ($subtree && $ctx !== null && $ctx->hasOrgReach()) {
            $conn = $this->getConnection();
            $ors = [];
            foreach ($ctx->subtreePaths() as $prefix) {
                // SUBTREE grant: this node and everything below. Trailing slash on
                // the path stops /1/5/ leaking into /1/57/.
                $ors[] = sprintf('%s.org_path LIKE %s', $targetTableAlias, $conn->quote(self::escapeLike($prefix) . '%'));
            }
            foreach ($ctx->selfPaths() as $exact) {
                // SELF grant: that node ONLY.
                $ors[] = sprintf('%s.org_path = %s', $targetTableAlias, $conn->quote($exact));
            }
            if ($ors !== []) {
                $sql .= ' AND (' . implode(' OR ', $ors) . ')';
            }
        }

        return $sql;
    }

    /**
     * A string that uniquely identifies the tenant + reach, pushed into a filter
     * parameter so Doctrine's query-cache key differs per tenant. MUST vary with
     * every input that changes {@see addFilterConstraint}, or queries leak across
     * tenants via the cache.
     */
    private function discriminatorFor(TenantContext $context): string
    {
        if (!$context->isActive()) {
            return 'off';
        }
        $tenantId = $context->tenantId();
        if ($tenantId === null) {
            return 'closed';
        }

        return 't' . $tenantId
            . '|st:' . implode(',', $context->subtreePaths())
            . '|sf:' . implode(',', $context->selfPaths());
    }

    /** Escape LIKE metacharacters so a path segment can't act as a wildcard. */
    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
