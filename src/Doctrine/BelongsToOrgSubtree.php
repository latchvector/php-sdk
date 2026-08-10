<?php

declare(strict_types=1);

namespace LatchVector\Sso\Doctrine;

use Doctrine\ORM\Mapping as ORM;

/**
 * Adds the `org_id` + `org_path` columns that {@see OrgSubtreeAware} needs. Use
 * together with {@see BelongsToTenant}:
 *
 *   #[ORM\Entity]
 *   class Patient implements \LatchVector\Sso\Doctrine\OrgSubtreeAware
 *   {
 *       use \LatchVector\Sso\Doctrine\BelongsToTenant;
 *       use \LatchVector\Sso\Doctrine\BelongsToOrgSubtree;
 *   }
 *
 * Index `(tenant_id, org_path text_pattern_ops)` for the subtree prefix match.
 */
trait BelongsToOrgSubtree
{
    #[ORM\Column(name: 'org_id', type: 'bigint', nullable: true)]
    private ?string $orgId = null;

    #[ORM\Column(name: 'org_path', type: 'string', length: 255, nullable: true)]
    private ?string $orgPath = null;

    public function getOrgId(): ?int
    {
        return $this->orgId === null ? null : (int) $this->orgId;
    }

    public function setOrgId(?int $orgId): void
    {
        $this->orgId = $orgId === null ? null : (string) $orgId;
    }

    public function getOrgPath(): ?string
    {
        return $this->orgPath;
    }

    public function setOrgPath(?string $orgPath): void
    {
        $this->orgPath = $orgPath;
    }
}
