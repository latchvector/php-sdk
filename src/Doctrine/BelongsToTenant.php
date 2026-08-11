<?php

declare(strict_types=1);

namespace LatchVector\Sso\Doctrine;

use Doctrine\ORM\Mapping as ORM;

/**
 * Drop-in implementation of {@see TenantAware}: maps a nullable `tenant_id`
 * column and its accessors.
 *
 *   #[ORM\Entity]
 *   class Invoice implements \LatchVector\Sso\Doctrine\TenantAware
 *   {
 *       use \LatchVector\Sso\Doctrine\BelongsToTenant;
 *   }
 *
 * Stored as `bigint` (tenant ids come from a BIGSERIAL) and exposed as `?int`.
 */
trait BelongsToTenant
{
    #[ORM\Column(name: 'tenant_id', type: 'bigint', nullable: true)]
    private ?string $tenantId = null;

    public function getTenantId(): ?int
    {
        return $this->tenantId === null ? null : (int) $this->tenantId;
    }

    public function setTenantId(?int $tenantId): void
    {
        $this->tenantId = $tenantId === null ? null : (string) $tenantId;
    }
}
