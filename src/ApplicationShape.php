<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Override;

use function array_is_list;
use function array_map;
use function is_array;

/**
 * A name applied to its type arguments -- `list<T>`, `map<K, V>`, or a bare `int` with none. Every
 * {@see TypeConstructor} and the bottom type `!` use this shape. Named sums use {@see EnumShape}.
 * See {@see Type::listOf()}, {@see Type::mapOf()} and the scalar factories.
 *
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class ApplicationShape implements ComparableShape
{
    /**
     * @param list<Type> $args
     */
    public function __construct(
        public readonly string $name,
        public readonly array $args = [],
    ) {
    }

    #[Override]
    public function accepts(mixed $value): bool
    {
        if ($this->name !== TypeConstructor::List->value && $this->name !== TypeConstructor::Map->value) {
            return Type::fromValue($value)->isSubtypeOf(Type::of($this));
        }
        if (!is_array($value)) {
            return false;
        }
        if ($this->name === TypeConstructor::List->value) {
            if (!array_is_list($value)) {
                return false;
            }
            /** @var mixed $item */
            foreach ($value as $item) {
                if (!$this->args[0]->accepts($item)) {
                    return false;
                }
            }
            return true;
        }
        if ($value !== [] && array_is_list($value)) {
            return false;
        }
        /** @var mixed $item */
        foreach ($value as $key => $item) {
            if (!$this->args[0]->accepts($key) || !$this->args[1]->accepts($item)) {
                return false;
            }
        }
        return true;
    }

    #[Override]
    public function refine(Type $actual): Type
    {
        $shape = $actual->shape();
        if (!$shape instanceof self || $shape->name !== $this->name) {
            return Type::of($this);
        }
        $arguments = [];
        foreach ($this->args as $index => $argument) {
            $arguments[] = $argument->refine($shape->args[$index]);
        }
        return Type::of(new self($this->name, $arguments));
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

    /**
     * @param array<string, true> $found
     * @return array<string, true>
     */
    #[Override]
    public function collectBinderNames(array $found): array
    {
        foreach ($this->args as $arg) {
            $found = $arg->collectBinderNames($found);
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
     * $supertype may be any shape, not just this one's own class -- that mismatch is rejected the same way a name or
     * argument mismatch is: two `ApplicationShape`s can still differ by name, or by argument even when the name
     * agrees -- `map<int, string>` is not a subtype of `map<int, int>`, even though both are named `map`. Once the
     * name agrees, {@see self::bind()}'s own invariant holds here too -- both sides have exactly as many arguments as
     * {@see TypeConstructor::typeArgumentCount()} fixes for that name -- so $supertype->args[$index] is trusted the
     * same way {@see self::bind()} trusts it, rather than guarded a second time.
     */
    #[Override]
    public function isSubtypeOf(ComparableShape $supertype): bool
    {
        if (!$supertype instanceof self || $this->name !== $supertype->name) {
            return false;
        }
        foreach ($this->args as $index => $arg) {
            if (!$arg->isSubtypeOf($supertype->args[$index])) {
                return false;
            }
        }
        return true;
    }

    /**
     * There is nothing to learn from $actual if its shape isn't this shape's own class, or if the names don't even
     * agree. Where they do, both sides have exactly as many arguments as {@see TypeConstructor::typeArgumentCount()}
     * fixes for that name -- an `ApplicationShape` is never built any other way, see {@see Type::listOf()},
     * {@see Type::mapOf()} and the other scalar factories -- so unlike {@see Signature::bind()}'s parameters, one
     * side is never shorter than the other.
     *
     * @param array<string, Type> $bindings
     * @return array<string, Type>
     */
    #[Override]
    public function bind(Type $actual, array $bindings): array
    {
        $actualShape = $actual->shape();
        if (!$actualShape instanceof self || $this->name !== $actualShape->name) {
            return $bindings;
        }
        foreach ($this->args as $index => $arg) {
            $bindings = $arg->bind($actualShape->args[$index], $bindings);
        }
        return $bindings;
    }
}
