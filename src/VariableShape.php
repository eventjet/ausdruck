<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Override;

use function array_key_exists;

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

    /**
     * $this->name, and nothing else: a variable is a placeholder with no arguments of its own to print -- see
     * {@see Type::var()}.
     */
    #[Override]
    public function toString(): string
    {
        return $this->name;
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
    public function isSubtypeOf(TypeShape $supertype): bool
    {
        return $supertype instanceof self && $this->name === $supertype->name;
    }

    /**
     * This variable's own binding: $actual, unless something has already bound this name, in which case the first
     * binding is the one that's kept -- see {@see Type::bind()} for why first-wins is the rule. Unlike every other
     * shape's {@see TypeShape::bind()}, this doesn't compare $actual's own shape against anything: a variable binds
     * to whatever it's matched against, not just another value of the same shape.
     *
     * @param array<string, Type> $bindings
     * @return array<string, Type>
     */
    #[Override]
    public function bind(Type $actual, array $bindings): array
    {
        return array_key_exists($this->name, $bindings) ? $bindings : [...$bindings, $this->name => $actual];
    }
}
