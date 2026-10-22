<?php

declare(strict_types=1);

namespace Hexis\AuditBundle\Tests\Integration\Fixtures;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Hexis\AuditBundle\Attribute\Auditable;

#[ORM\Entity]
#[ORM\Table(name: 'test_full_audited_group')]
#[Auditable(mode: 'full')]
class FullAuditedGroup
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    #[ORM\Column(type: 'string', length: 100)]
    public string $name = '';

    /** @var Collection<int, Teacher> */
    #[ORM\ManyToMany(targetEntity: Teacher::class)]
    #[ORM\JoinTable(name: 'test_full_audited_group_teacher')]
    #[ORM\JoinColumn(name: 'group_id', referencedColumnName: 'id')]
    #[ORM\InverseJoinColumn(name: 'teacher_id', referencedColumnName: 'id')]
    public Collection $teachers;

    #[ORM\Embedded(class: Address::class, columnPrefix: 'address_')]
    public Address $address;

    /** Not mapped: full-mode images read every declared property. */
    public ?object $tag = null;

    public function __construct()
    {
        $this->teachers = new ArrayCollection();
        $this->address = new Address();
    }
}
