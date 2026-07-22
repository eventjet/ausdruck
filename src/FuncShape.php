<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

/**
 * A function type -- see {@see Type::func()}. The {@see Signature} held here is always binder-less: it doesn't yet
 * know, at the point {@see Type::func()} builds it, whether it will be read as a complete top-level declaration or
 * embedded as another one's own parameter or return type -- only the first of those owns a binder, since rank-1
 * polymorphism gives every variable reachable inside a signature to whichever one encloses it. {@see Type::asFunction()}
 * is what derives that binder, reading this signature as the complete declaration it's being asked for.
 *
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class FuncShape implements TypeShape
{
    public function __construct(
        public readonly Signature $signature,
    ) {
    }
}
