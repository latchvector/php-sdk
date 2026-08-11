<?php

declare(strict_types=1);

namespace LatchVector\Sso\Doctrine;

/**
 * Marks a Doctrine entity as tenant-owned. Once an entity implements this, the
 * {@see TenantFilter} confines every query on it to the current tenant, and
 * {@see TenantStampListener} stamps a new row with that tenant on insert.
 *
 * The easy way to satisfy it is the {@see BelongsToTenant} trait (it maps the
 * `tenant_id` column and implements these methods). Map the column yourself only
 * if you don't use attribute mapping.
 */
interface TenantAware
{
    public function getTenantId(): ?int;

    public function setTenantId(?int $tenantId): void;
}
