<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Override;

/**
 * A type variable -- see {@see Type::var()}: a placeholder a generic signature's call site decides, not a type with
 * parts of its own, so this shape carries nothing but the name it was given.
 *
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class VariableShape implements TypeShape
{
    public function __construct(
        public readonly string $name,
    ) {
    }

    #[Override]
    public function name(): string
    {
        return $this->name;
    }

    /**
     * @param array<string, true> $found
     * @return array<string, true>
     */
    #[Override]
    public function collectVariables(array $found): array
    {
        $found[$this->name] = true;
        return $found;
    }

    #[Override]
    public function toString(): string
    {
        return TypeSyntax::application($this->name, []);
    }

    /**
     * Whatever this variable's own binding turned out to be, or `any` if nothing bound it -- unlike every other
     * shape's {@see TypeShape::substitute()}, this doesn't rebuild its own kind: what a variable substitutes to can
     * be any shape at all, not necessarily another variable.
     *
     * @param array<string, Type> $bindings
     */
    #[Override]
    public function substitute(array $bindings): Type
    {
        return $bindings[$this->name] ?? Type::any();
    }
}
