<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Parser;

use Eventjet\Ausdruck\Type;
use Override;
use Stringable;

/**
 * A resolvable unit of parsed type syntax, told apart by which of its three subclasses it actually is: a name
 * applied to arguments ({@see ApplicationTypeNode}), a function type ({@see FunctionTypeNode}), or a struct type
 * ({@see StructTypeNode}). Carries only what's common to all three: the span it was written at, how to print it
 * back, and how to resolve it, which is why this doesn't also declare a $name or an $args a subclass would
 * otherwise have to invent to fill.
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

    /**
     * Resolves $this against $resolution -- see {@see TypeResolution::resolve()}, which does nothing but call back
     * here: one method per subclass, rather than an `instanceof` chain on {@see TypeResolution} repeated for every
     * shape of node, so a fourth subclass has to implement this to exist at all, which the compiler enforces, rather
     * than compiling fine and only failing at runtime.
     */
    abstract public function resolveWith(TypeResolution $resolution): Type|TypeError;
}
