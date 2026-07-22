<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Override;

use function array_map;

/**
 * A name applied to its type arguments -- `list<T>`, `map<K, V>`, or a bare `int` with none. Every
 * {@see TypeConstructor} other than `Option` and `Some` themselves, plus `None`, `any` and `never`, is one of these;
 * see {@see Type::listOf()}, {@see Type::mapOf()}, {@see Type::option()} and the other scalar factories.
 *
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class ApplicationShape implements TypeShape
{
    /**
     * @param list<Type> $args
     */
    public function __construct(
        public readonly string $name,
        public readonly array $args = [],
    ) {
    }

    /**
     * @param array<string, true> $found
     * @return array<string, true>
     */
    #[Override]
    public function collectVariables(array $found): array
    {
        foreach ($this->args as $arg) {
            $found = $arg->collectVariables($found);
        }
        return $found;
    }

    #[Override]
    public function toString(): string
    {
        return TypeSyntax::application(
            $this->name,
            array_map(static fn(Type $arg): string => (string)$arg, $this->args),
        );
    }

    /**
     * @param array<string, Type> $bindings
     */
    #[Override]
    public function substitute(array $bindings): Type
    {
        return Type::of(
            new self($this->name, array_map(static fn(Type $arg): Type => $arg->substitute($bindings), $this->args)),
        );
    }

    /**
     * $other may be any shape, not just this one's own class -- that mismatch is rejected the same way a name or
     * argument mismatch is: two `ApplicationShape`s can still differ by name, or by argument even when the name
     * agrees -- `map<int, string>` is not a subtype of `map<int, int>`, even though both are named `map`.
     */
    #[Override]
    public function isSubtypeOf(TypeShape $other): bool
    {
        if (!$other instanceof self || $this->name !== $other->name) {
            return false;
        }
        foreach ($this->args as $index => $arg) {
            $otherArg = $other->args[$index] ?? null;
            if ($otherArg === null || !$arg->isSubtypeOf($otherArg)) {
                return false;
            }
        }
        return true;
    }

    /**
     * There is nothing to learn from $other if it isn't this shape's own class, or if the names don't even agree.
     * Where they do, both sides have exactly as many arguments as {@see TypeConstructor::typeArgumentCount()} fixes
     * for that name -- an `ApplicationShape` is never built any other way, see {@see Type::listOf()},
     * {@see Type::mapOf()} and the other scalar factories -- so unlike {@see FuncShape::bind()}'s parameters, one
     * side is never shorter than the other.
     *
     * @param array<string, Type> $bindings
     * @return array<string, Type>
     */
    #[Override]
    public function bind(TypeShape $other, array $bindings): array
    {
        if (!$other instanceof self || $this->name !== $other->name) {
            return $bindings;
        }
        foreach ($this->args as $index => $arg) {
            $bindings = $arg->bind($other->args[$index], $bindings);
        }
        return $bindings;
    }
}
