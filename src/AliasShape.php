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
    public function substitute(array $bindings): Type
    {
        return Type::of(new self($this->name, $this->target->substitute($bindings)));
    }

    /**
     * Never actually called: {@see Type::isSubtypeOf()} canonicalizes both sides -- seeing through every alias --
     * before it ever compares shapes, so a {@see self} is never $this here. Still implemented, since a shape has to.
     */
    #[Override]
    public function isSubtypeOf(TypeShape $other): bool
    {
        return false;
    }

    /**
     * Never actually called, for the same reason {@see self::isSubtypeOf()} isn't: {@see Type::bind()} canonicalizes
     * both sides before comparing shapes too.
     *
     * @param array<string, Type> $bindings
     * @return array<string, Type>
     */
    #[Override]
    public function bind(TypeShape $other, array $bindings): array
    {
        return $bindings;
    }
}
