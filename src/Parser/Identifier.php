<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Parser;

use Override;
use Stringable;

/**
 * A name with the span it was written at: a struct field's name, or a type variable's, in either case not itself a
 * type, which is why it isn't a {@see TypeNode}. {@see TypeParser} is the only thing that builds one.
 *
 * @internal
 * @psalm-internal Eventjet\Ausdruck\Parser
 */
final class Identifier implements Stringable
{
    public function __construct(
        public readonly string $name,
        public readonly Span $location,
    ) {
    }

    #[Override]
    public function __toString(): string
    {
        return $this->name;
    }
}
