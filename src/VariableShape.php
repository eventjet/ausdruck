<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use LogicException;
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

    /**
     * Two variables of the same name are the same variable -- see {@see Type::var()}.
     */
    #[Override]
    public function isSubtypeOf(TypeShape $other): bool
    {
        return $other instanceof self && $this->name === $other->name;
    }

    /**
     * Unreachable: {@see Type::bind()} asks whether $this is a variable, and binds it directly, before it ever
     * reaches a shape comparison.
     *
     * @param array<string, Type> $bindings
     * @return array<string, Type>
     */
    #[Override]
    public function bind(TypeShape $other, array $bindings): array
    {
        throw new LogicException(
            'VariableShape::bind() is unreachable: Type::bind() binds a variable directly, before it ever reaches a '
                . 'shape comparison',
        );
    }
}
