<?php

declare(strict_types=1);

namespace LatchVector\Sso\Doctrine;

/**
 * Declares that a tenant-owned entity is DELIBERATELY visible to the whole
 * tenant — every user under the tenant root sees its rows, regardless of which
 * org in the tree they belong to.
 *
 * This is the explicit opt-out from the default. By default tenant data is
 * org-tree isolated ({@see OrgSubtreeAware}) so sibling orgs can't see each
 * other's rows; a plain {@see TenantAware} entity that declares NEITHER mode is
 * rejected (a loud error, not a silent whole-tenant leak). Implement this only
 * for genuinely shared reference data (e.g. a tenant-wide settings table).
 */
interface TenantWide extends TenantAware
{
}
