<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Parser;

use Override;
use Stringable;

/**
 * A resolvable unit of parsed type syntax, told apart by which of its three subclasses it actually is: a name
 * applied to arguments ({@see ApplicationTypeNode}), a function type ({@see FunctionTypeNode}), or a struct type
 * ({@see StructTypeNode}) -- the same three {@see TypeResolution::resolve()} dispatches on. Carries only what's
 * common to all three: the span it was written at, and how to print it back, which is why this doesn't also declare
 * a $name or an $args a subclass would otherwise have to invent to fill.
 *
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
abstract class TypeNode implements Stringable
{
    public function __construct(
        public readonly Span $location,
    ) {
    }

    #[Override]
    abstract public function __toString(): string;
}
