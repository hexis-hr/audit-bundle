<?php

declare(strict_types=1);

namespace Hexis\AuditBundle\Serializer;

use Symfony\Component\PropertyAccess\PropertyAccessor;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

/**
 * Cycle-safe snapshot normalizer. Produces a JSON-safe array for an audited entity.
 *
 * Scalars, enums, and DateTimes are captured as-is. Related entities are captured as
 * `{"@ref": <fqcn>, "id": <identifier>}` — never dereferenced, so capture never triggers
 * a lazy-load cascade. Works from public/readable properties via PropertyAccess.
 */
final class AuditSnapshotNormalizer
{
    private readonly PropertyAccessorInterface $accessor;

    /** @var array<class-string, list<string>> reflection cache per class */
    private array $propertyCache = [];

    public function __construct(?PropertyAccessorInterface $accessor = null)
    {
        $this->accessor = $accessor ?? new PropertyAccessor();
    }

    /**
     * @param list<string> $ignoreFields
     * @return array<string, mixed>
     */
    public function normalize(object $entity, array $ignoreFields = []): array
    {
        $ignore = array_flip($ignoreFields);
        $out = [];
        foreach ($this->propertiesOf($entity::class) as $name) {
            if (isset($ignore[$name])) {
                continue;
            }
            try {
                $value = $this->accessor->getValue($entity, $name);
            } catch (\Throwable) {
                continue;
            }
            $out[$name] = $this->normalizeValue($value);
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function propertiesOf(string $class): array
    {
        if (isset($this->propertyCache[$class])) {
            return $this->propertyCache[$class];
        }

        $reflection = new \ReflectionClass($class);
        $names = [];
        foreach ($reflection->getProperties() as $property) {
            if ($property->isStatic()) {
                continue;
            }
            $names[] = $property->getName();
        }

        return $this->propertyCache[$class] = $names;
    }

    private function normalizeValue(mixed $value): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if ($value instanceof \UnitEnum) {
            return $value->name;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }

        if (\is_array($value) || $value instanceof \Traversable) {
            $array = \is_array($value) ? $value : iterator_to_array($value, false);
            $result = [];
            foreach ($array as $key => $item) {
                $result[$key] = $this->normalizeValue($item);
            }

            return $result;
        }

        if (\is_object($value)) {
            // Try to read the identifier without dereferencing anything else on the entity.
            if (method_exists($value, 'getId')) {
                try {
                    $id = $value->getId();
                    if ($id !== null) {
                        return ['@ref' => $value::class, 'id' => is_scalar($id) ? $id : (string) $id];
                    }
                } catch (\Throwable) {
                    // fall through
                }
            }

            return ['@ref' => $value::class];
        }

        return (string) $value;
    }
}
