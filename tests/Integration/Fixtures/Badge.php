<?php

declare(strict_types=1);

namespace Hexis\AuditBundle\Tests\Integration\Fixtures;

use Doctrine\ORM\Mapping as ORM;

/**
 * Mapped, but not an entity: the metadata factory does not report it as transient, like an embeddable
 * mapped in XML.
 */
#[ORM\MappedSuperclass]
class Badge
{
    public function __construct(
        #[ORM\Column(type: 'string', length: 20, nullable: true)]
        public ?string $code = null,
    ) {
    }

    public function getId(): ?string
    {
        return $this->code;
    }
}
