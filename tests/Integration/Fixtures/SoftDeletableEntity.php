<?php

declare(strict_types=1);

namespace Hexis\AuditBundle\Tests\Integration\Fixtures;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Hexis\AuditBundle\Attribute\Auditable;

#[ORM\Entity]
#[ORM\Table(name: 'test_soft_deletable')]
#[Gedmo\SoftDeleteable(fieldName: 'deletedAt', hardDelete: true)]
#[Auditable(mode: 'changed_fields')]
class SoftDeletableEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    #[ORM\Column(type: 'string', length: 100)]
    public string $name = '';

    #[ORM\Column(name: 'deleted_at', type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $deletedAt = null;
}
