<?php

declare(strict_types=1);

namespace Hexis\AuditBundle\Tests\Integration\Fixtures;

use Doctrine\ORM\Mapping as ORM;
use Hexis\AuditBundle\Attribute\Auditable;

#[ORM\Entity]
#[ORM\Table(name: 'test_audited')]
#[Auditable(mode: 'changed_fields')]
class AuditedEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    #[ORM\Column(type: 'string', length: 100)]
    public string $name = '';

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    public ?string $email = null;
}
