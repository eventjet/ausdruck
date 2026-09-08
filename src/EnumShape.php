<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Override;

use function array_combine;
use function array_keys;
use function array_map;

/** @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class EnumShape implements ComparableShape
{
    /** @param list<Type> $arguments */
    public function __construct(public readonly EnumDefinition $definition, public readonly array $arguments)
    {
    }

    #[Override]
    public function collectVariables(array $found): array
    {
        foreach ($this->arguments as $argument) {
            $found = $argument->collectVariables($found);
        }
        return $found;
    }

    #[Override]
    public function collectBinderNames(array $found): array
    {
        foreach ($this->arguments as $argument) {
            $found = $argument->collectBinderNames($found);
        }
        return $found;
    }

    #[Override]
    public function toString(): string
    {
        return TypeSyntax::application($this->definition->name, array_map(static fn(Type $t): string => (string)$t, $this->arguments));
    }

    #[Override]
    public function substitute(array $bindings): Type
    {
        return $this->definition->type(...array_map(static fn(Type $t): Type => $t->substitute($bindings), $this->arguments));
    }

    #[Override]
    public function isSubtypeOf(ComparableShape $supertype): bool
    {
        if (!$supertype instanceof self || $this->definition !== $supertype->definition) {
            return false;
        }
        foreach (array_keys($this->definition->variants) as $variant) {
            $superFields = $supertype->fields($variant);
            foreach ($this->fields($variant) as $index => $field) {
                if (!$field->isSubtypeOf($superFields[$index])) {
                    return false;
                }
            }
        }
        return true;
    }

    #[Override]
    public function bind(Type $actual, array $bindings): array
    {
        $shape = $actual->shape();
        if (!$shape instanceof self || $shape->definition !== $this->definition) {
            return $bindings;
        }
        foreach ($this->arguments as $index => $argument) {
            if ($shape->arguments[$index]->equals(Type::never())) {
                continue;
            }
            $bindings = $argument->bind($shape->arguments[$index], $bindings);
        }
        return $bindings;
    }

    /** @return list<Type> */
    public function fields(string $variant): array
    {
        $bindings = array_combine($this->definition->parameters, $this->arguments);
        return array_map(static fn(Type $t): Type => $t->substitute($bindings), $this->definition->variants[$variant]);
    }
}
