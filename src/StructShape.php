<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Override;

use function array_map;

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

    #[Override]
    public function name(): string
    {
        return 'Struct';
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
}
