<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Override;

use function array_map;
use function array_slice;
use function count;

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
     * Same name, same shape, but two `ApplicationShape`s of that name can still differ argument by argument --
     * `map<int, string>` is not a subtype of `map<int, int>`, even though both are named `map`. $other isn't
     * necessarily an {@see ApplicationShape} at all, so a shape mismatch is rejected here, in the same breath as a
     * name mismatch, rather than upstream.
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
     * Two types of the same shape can still be of different sizes: a lambda declares fewer parameters than the
     * signature asks for. What the two have in common is what there is to learn from -- and nothing is, if $other
     * isn't even an {@see ApplicationShape}, or doesn't share $this's name.
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
        foreach (array_slice($this->args, 0, count($other->args)) as $index => $arg) {
            $bindings = $arg->bind($other->args[$index], $bindings);
        }
        return $bindings;
    }
}
