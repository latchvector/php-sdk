<?php

declare(strict_types=1);

namespace LatchVector\Sso\Doctrine;

/**
 * A tenant-owned entity that is ALSO confined to the caller's reach *within* the
 * tenant (the org subtree), not just the tenant as a whole. Implementing this
 * (in addition to being {@see TenantAware}) switches the filter into "subtree"
 * mode: a SELF grant sees exactly its node, a SUBTREE grant sees its node and
 * everything below — decided entirely by the token.
 *
 * Satisfy it with the {@see BelongsToOrgSubtree} trait alongside
 * {@see BelongsToTenant}. Needs `org_id` and `org_path` columns.
 */
interface OrgSubtreeAware extends TenantAware
{
    public function getOrgId(): ?int;

    public function setOrgId(?int $orgId): void;

    public function getOrgPath(): ?string;

    public function setOrgPath(?string $orgPath): void;
}
