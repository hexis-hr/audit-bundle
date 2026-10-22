<?php

declare(strict_types=1);

namespace Hexis\AuditBundle\Tests\Integration\Fixtures;

/**
 * Not mapped. Its getId() either throws or returns something without a string form.
 */
final class OpaqueValue
{
    public function __construct(private readonly bool $throws)
    {
    }

    public function getId(): object
    {
        if ($this->throws) {
            throw new \RuntimeException('getId() is not available');
        }

        return new \stdClass();
    }
}
