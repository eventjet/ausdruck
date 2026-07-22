<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Override;

/**
 * An alias -- see {@see Type::alias()}: a name for one complete type, printed as that name rather than as $target,
 * which is why $target's own arguments, fields or signature never appear directly on the alias's own {@see Type}.
 * Everything that needs to see through the name resolves $target first.
 *
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class AliasShape implements TypeShape
{
    public function __construct(
        public readonly string $name,
        public readonly Type $target,
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
        return $this->target->collectVariables($found);
    }

    #[Override]
    public function toString(): string
    {
        return TypeSyntax::application($this->name, []);
    }

    /**
     * @param array<string, Type> $bindings
     */
    #[Override]
    public function substitute(array $bindings): static
    {
        return new self($this->name, $this->target->substitute($bindings));
    }
}
