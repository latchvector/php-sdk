<?php

declare(strict_types=1);

namespace LatchVector\Sso\Tests\Doctrine\Fixtures;

use Doctrine\ORM\Mapping as ORM;
use LatchVector\Sso\Doctrine\BelongsToTenant;
use LatchVector\Sso\Doctrine\TenantAware;

/**
 * A misconfigured entity: tenant-owned but declaring NEITHER OrgSubtreeAware nor
 * TenantWide. Exists only to prove the filter/listener reject it loudly instead
 * of silently exposing it to the whole tenant.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ledger')]
class Ledger implements TenantAware
{
    use BelongsToTenant;

    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue]
    private ?int $id = null;

    #[ORM\Column(type: 'string')]
    public string $name;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function getId(): ?int
    {
        return $this->id;
    }
}
