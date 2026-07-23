<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Override;

use function array_intersect_key;
use function array_key_exists;
use function array_map;
use function sprintf;

/**
 * A struct, written as its fields between `{ }` rather than as a name -- see {@see Type::struct()}.
 *
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class StructShape implements ComparableShape
{
    /**
     * @param array<string, Type> $fields
     */
    public function __construct(
        public readonly array $fields,
    ) {
    }

    /**
     * @param array<string, true> $found
     * @return array<string, true>
     */
    #[Override]
    public function collectVariables(array $found): array
    {
        foreach ($this->fields as $field) {
            $found = $field->collectVariables($found);
        }
        return $found;
    }

    /**
     * @param array<string, true> $found
     * @return array<string, true>
     */
    #[Override]
    public function collectBinderNames(array $found): array
    {
        foreach ($this->fields as $field) {
            $found = $field->collectBinderNames($found);
        }
        return $found;
    }

    #[Override]
    public function toString(): string
    {
        $fields = [];
        foreach ($this->fields as $name => $fieldType) {
            $fields[] = sprintf('%s: %s', $name, $fieldType);
        }
        return TypeSyntax::struct($fields);
    }

    /**
     * @param array<string, Type> $bindings
     */
    #[Override]
    public function substitute(array $bindings): Type
    {
        return Type::of(new self(array_map(static fn(Type $field): Type => $field->substitute($bindings), $this->fields)));
    }

    /**
     * A struct is a subtype of another if it has at least the fields the other does, each of a subtype of the
     * other's -- it may have more, which is what makes a struct type structural rather than nominal. $supertype may
     * be any shape, not just this one's own class; that mismatch is rejected the same way a missing or wrong-typed
     * field is.
     */
    #[Override]
    public function isSubtypeOf(ComparableShape $supertype): bool
    {
        if (!$supertype instanceof self) {
            return false;
        }
        foreach ($supertype->fields as $name => $fieldType) {
            if (!array_key_exists($name, $this->fields)) {
                return false;
            }
            if (!$this->fields[$name]->isSubtypeOf($fieldType)) {
                return false;
            }
        }
        return true;
    }

    /**
     * A struct can be written with fewer fields than one reaches into. What the two have in common is what there is
     * to learn from -- nothing, if $actual's shape isn't this shape's own class either.
     *
     * @param array<string, Type> $bindings
     * @return array<string, Type>
     */
    #[Override]
    public function bind(Type $actual, array $bindings): array
    {
        $actualShape = $actual->shape();
        if (!$actualShape instanceof self) {
            return $bindings;
        }
        foreach (array_intersect_key($this->fields, $actualShape->fields) as $name => $field) {
            $bindings = $field->bind($actualShape->fields[$name], $bindings);
        }
        return $bindings;
    }
}
