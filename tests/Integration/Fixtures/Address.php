<?php

declare(strict_types=1);

namespace Hexis\AuditBundle\Tests\Integration\Fixtures;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Embeddable]
class Address
{
    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    public ?string $street = null;

    /**
     * Not an identifier: a reference to a non-entity object uses any getId() it has.
     */
    public function getId(): ?string
    {
        return $this->street;
    }
}
