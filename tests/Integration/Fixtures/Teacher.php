<?php

declare(strict_types=1);

namespace Hexis\AuditBundle\Tests\Integration\Fixtures;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'test_teacher')]
class Teacher
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    #[ORM\Column(type: 'string', length: 100)]
    public string $name = '';

    /**
     * Owning side of AuditedGroup::$assistants, so that one is an inverse side.
     *
     * @var Collection<int, AuditedGroup>
     */
    #[ORM\ManyToMany(targetEntity: AuditedGroup::class, inversedBy: 'assistants')]
    #[ORM\JoinTable(name: 'test_group_assistant')]
    #[ORM\JoinColumn(name: 'teacher_id', referencedColumnName: 'id')]
    #[ORM\InverseJoinColumn(name: 'group_id', referencedColumnName: 'id')]
    public Collection $assistedGroups;

    public function __construct(string $name = '')
    {
        $this->name = $name;
        $this->assistedGroups = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }
}
