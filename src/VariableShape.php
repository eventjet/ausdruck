<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use LogicException;
use Override;

use function assert;

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
    public function isSubtypeOfSame(TypeShape $other): bool
    {
        assert($other instanceof self);
        return $this->name === $other->name;
    }

    /**
     * Never actually called: {@see Type::bind()} asks whether $this is a variable, and binds it, before it ever
     * compares two shapes' own classes against each other -- a {@see self} is never the left side of that
     * comparison, so this never runs for real. Exists only because {@see TypeShape} requires it.
     *
     * @param array<string, Type> $bindings
     * @return array<string, Type>
     */
    #[Override]
    public function bindSame(TypeShape $other, array $bindings): array
    {
        throw new LogicException('Unreachable: Type::bind() resolves a variable on the left before comparing shapes');
    }
}
