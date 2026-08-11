<?php

declare(strict_types=1);

namespace LatchVector\Sso\Doctrine;

/**
 * The one-line default for tenant-owned data: maps all three columns
 * (`tenant_id`, `org_id`, `org_path`) and gives the entity org-tree isolation,
 * so sibling orgs never see each other's rows.
 *
 *   #[ORM\Entity]
 *   class Patient implements \LatchVector\Sso\Doctrine\OrgSubtreeAware
 *   {
 *       use \LatchVector\Sso\Doctrine\BelongsToTenantTree;
 *   }
 *
 * Equivalent to using {@see BelongsToTenant} + {@see BelongsToOrgSubtree}
 * together. Index `(tenant_id, org_path text_pattern_ops)` for the prefix match.
 */
trait BelongsToTenantTree
{
    use BelongsToTenant;
    use BelongsToOrgSubtree;
}
