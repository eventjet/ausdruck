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
     * Never actually called: a variable substitutes to whatever its binding turned out to be, which can be any
     * shape at all, not necessarily another variable, so {@see Type::substitute()} special-cases one before ever
     * asking its shape -- see {@see TypeShape}'s own docblock. This exists only to satisfy the interface.
     *
     * @param array<string, Type> $bindings
     */
    #[Override]
    public function substitute(array $bindings): static
    {
        throw new LogicException('A variable substitutes through Type::substitute(), never through its own shape');
    }
}
