<?php

declare(strict_types=1);

namespace LatchVector\Sso\Tests\Doctrine\Fixtures;

use Doctrine\ORM\Mapping as ORM;
use LatchVector\Sso\Doctrine\BelongsToOrgSubtree;
use LatchVector\Sso\Doctrine\BelongsToTenant;
use LatchVector\Sso\Doctrine\OrgSubtreeAware;

#[ORM\Entity]
#[ORM\Table(name: 'patient')]
class Patient implements OrgSubtreeAware
{
    use BelongsToTenant;
    use BelongsToOrgSubtree;

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
