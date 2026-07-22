<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Override;

use function array_intersect_key;
use function array_key_exists;
use function array_map;
use function assert;

/**
 * A struct, written as its fields between `{ }` rather than as a name -- see {@see Type::struct()}.
 *
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class StructShape implements TypeShape
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

    #[Override]
    public function toString(): string
    {
        $fields = [];
        foreach ($this->fields as $name => $fieldType) {
            $fields[] = $name . ': ' . (string)$fieldType;
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
     * other's -- it may have more, which is what makes a struct type structural rather than nominal.
     */
    #[Override]
    public function isSubtypeOfSame(TypeShape $other): bool
    {
        assert($other instanceof self);
        foreach ($other->fields as $name => $fieldType) {
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
     * to learn from.
     *
     * @param array<string, Type> $bindings
     * @return array<string, Type>
     */
    #[Override]
    public function bindSame(TypeShape $other, array $bindings): array
    {
        assert($other instanceof self);
        foreach (array_intersect_key($this->fields, $other->fields) as $name => $field) {
            $bindings = $field->bind($other->fields[$name], $bindings);
        }
        return $bindings;
    }
}
