<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

/**
 * A function type -- see {@see Type::func()}. The {@see Signature} held here doesn't know, by itself, whether it
 * will be read as a complete top-level declaration or embedded as another one's own parameter or return type --
 * only the first of those actually owns the {@see Signature::binder()} it derives, since rank-1 polymorphism gives
 * every variable reachable inside a signature to whichever one encloses it. Nothing on the signature records which
 * one this is; {@see Type::asFunction()} and {@see Type::toString()} decide that by where this shape sits in a
 * {@see Type} tree, not by anything stored here.
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
