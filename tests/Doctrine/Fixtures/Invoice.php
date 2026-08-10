<?php

declare(strict_types=1);

namespace LatchVector\Sso\Tests\Doctrine\Fixtures;

use Doctrine\ORM\Mapping as ORM;
use LatchVector\Sso\Doctrine\BelongsToTenant;
use LatchVector\Sso\Doctrine\TenantAware;

#[ORM\Entity]
#[ORM\Table(name: 'invoice')]
class Invoice implements TenantAware
{
    use BelongsToTenant;

    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue]
    private ?int $id = null;

    #[ORM\Column(type: 'string')]
    public string $number;

    public function __construct(string $number)
    {
        $this->number = $number;
    }

    public function getId(): ?int
    {
        return $this->id;
    }
}
