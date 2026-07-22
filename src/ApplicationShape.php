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
    public function substitute(array $bindings): static
    {
        return new self($this->name, array_map(static fn(Type $arg): Type => $arg->substitute($bindings), $this->args));
    }
}
