<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use LogicException;
use Override;

/**
 * An alias -- see {@see Type::alias()}: a name for one complete type, printed as that name rather than as $target,
 * which is why $target's own arguments, fields or signature never appear directly on the alias's own {@see Type}.
 * Everything that needs to see through the name resolves $target first.
 *
 * $target can never itself reach a free variable -- {@see Type::alias()} rejects one that does -- so
 * {@see self::collectVariables()} and {@see self::substitute()}, real delegations to $target though they are, can
 * only ever answer $found unchanged and a $target that reads the same, respectively. {@see Type::isSubtypeOf()} and
 * {@see Type::bind()} both canonicalize $target away before ever comparing shapes, so {@see self::isSubtypeOf()} and
 * {@see self::bind()} below are never called at all; both throw rather than answer either question quietly wrong.
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

    /**
     * $found unchanged: an alias prints as $this->name alone -- never as $target -- so a `fn<...>` binder inside
     * $target never surfaces in the text an enclosing binder could shadow, and there is nothing here for
     * {@see Signature::over()} to reject a derived binder against. Unlike {@see self::collectVariables()}, which has to
     * see through $target to answer for the variables it reaches, this stops at the name.
     *
     * @param array<string, true> $found
     * @return array<string, true>
     */
    #[Override]
    public function collectBinderNames(array $found): array
    {
        return $found;
    }

    /**
     * $this->name, and nothing else: an alias is a name for one complete type, printed as that name alone -- see this
     * class's own docblock -- never as a name applied to arguments, since {@see Type::alias()} never lets $this->name
     * carry any of its own.
     */
    #[Override]
    public function toString(): string
    {
        return $this->name;
    }

    /**
     * @param array<string, Type> $bindings
     */
    #[Override]
    public function substitute(array $bindings): Type
    {
        return Type::of(new self($this->name, $this->target->substitute($bindings)));
    }

    #[Override]
    public function isSubtypeOf(TypeShape $supertype): bool
    {
        throw new LogicException(
            'AliasShape::isSubtypeOf() is unreachable: Type::isSubtypeOf() sees through every alias first',
        );
    }

    /**
     * @param array<string, Type> $bindings
     * @return array<string, Type>
     */
    #[Override]
    public function bind(Type $actual, array $bindings): array
    {
        throw new LogicException('AliasShape::bind() is unreachable: Type::bind() sees through every alias first');
    }
}
