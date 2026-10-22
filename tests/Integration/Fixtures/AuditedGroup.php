<?php

declare(strict_types=1);

namespace Hexis\AuditBundle\Tests\Integration\Fixtures;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Hexis\AuditBundle\Attribute\Auditable;

#[ORM\Entity]
#[ORM\Table(name: 'test_audited_group')]
#[Auditable(mode: 'changed_fields')]
class AuditedGroup
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    #[ORM\Column(type: 'string', length: 100)]
    public string $name = '';

    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'payload', type: 'json', nullable: true)]
    public ?array $payload = null;

    #[ORM\ManyToOne(targetEntity: Teacher::class)]
    #[ORM\JoinColumn(name: 'lead_id', referencedColumnName: 'id', nullable: true)]
    public ?Teacher $lead = null;

    /** @var Collection<int, Teacher> */
    #[ORM\ManyToMany(targetEntity: Teacher::class)]
    #[ORM\JoinTable(name: 'test_audited_group_teacher')]
    #[ORM\JoinColumn(name: 'group_id', referencedColumnName: 'id')]
    #[ORM\InverseJoinColumn(name: 'teacher_id', referencedColumnName: 'id')]
    public Collection $teachers;

    /** @var Collection<int, Teacher> */
    #[ORM\ManyToMany(targetEntity: Teacher::class, mappedBy: 'assistedGroups')]
    public Collection $assistants;

    public function __construct()
    {
        $this->teachers = new ArrayCollection();
        $this->assistants = new ArrayCollection();
    }
}
