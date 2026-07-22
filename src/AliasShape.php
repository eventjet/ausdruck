<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

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
        public readonly Type $target,
    ) {
    }
}
