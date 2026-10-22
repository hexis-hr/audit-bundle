<?php

declare(strict_types=1);

namespace Hexis\AuditBundle\Tests\Integration\Fixtures;

use Doctrine\ORM\Mapping as ORM;

/**
 * Composite identifier, assigned by the application, and no getId().
 */
#[ORM\Entity]
#[ORM\Table(name: 'test_term')]
class Term
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'year', type: 'integer')]
        public int $year,
        #[ORM\Id]
        #[ORM\Column(name: 'season', type: 'string', length: 20)]
        public string $season,
    ) {
    }
}
