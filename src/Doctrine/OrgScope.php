<?php

declare(strict_types=1);

namespace LatchVector\Sso\Doctrine;

use Doctrine\ORM\QueryBuilder;
use LatchVector\Sso\Tenancy\OrgPath;
use LatchVector\Sso\Tenancy\OrgReachException;
use LatchVector\Sso\Tenancy\TenantContext;

/**
 * Narrow a Doctrine query to a chosen org WITHIN the caller's reach — the
 * Doctrine equivalent of the Laravel `forOrg` scope. Entities carry no query
 * methods of their own, so this adds the condition to a QueryBuilder.
 *
 *   OrgScope::apply($qb, 'p', '/2/5/', includeSubtree: false, context: $context);
 *
 * It ANDs onto the always-on tenant filter, so it can only shrink the result and
 * refuses a branch the token can't see ({@see OrgReachException} → map to 403).
 * A bypass caller or a machine token (tenant-wide) may target any org in the
 * tenant.
 */
final class OrgScope
{
    /**
     * @param string $alias the entity alias in the QueryBuilder (e.g. 'p')
     */
    public static function apply(
        QueryBuilder $qb,
        string $alias,
        string $orgPath,
        bool $includeSubtree,
        TenantContext $context,
    ): QueryBuilder {
        $path = OrgPath::normalize($orgPath);
        $context->assertReach($path, $includeSubtree);

        if ($includeSubtree) {
            return $qb
                ->andWhere(sprintf("%s.orgPath LIKE :lv_org_path ESCAPE '\\'", $alias))
                ->setParameter('lv_org_path', OrgPath::escapeLike($path) . '%');
        }

        return $qb
            ->andWhere(sprintf('%s.orgPath = :lv_org_path', $alias))
            ->setParameter('lv_org_path', $path);
    }

    /**
     * Narrow to a single org node by id. Safe by construction (ANDs onto the
     * tenant filter); an out-of-reach id yields no rows. Use {@see apply} with a
     * path when you want an explicit 403 instead of an empty result.
     */
    public static function byId(QueryBuilder $qb, string $alias, int $orgId): QueryBuilder
    {
        return $qb
            ->andWhere(sprintf('%s.orgId = :lv_org_id', $alias))
            ->setParameter('lv_org_id', (string) $orgId);
    }
}
