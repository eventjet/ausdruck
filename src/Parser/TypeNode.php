<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Parser;

use Override;
use Stringable;

use function implode;
use function sprintf;

/**
 * A name applied to type arguments, e.g. `map<T, U>` or a bare `int`. {@see FieldTypeNode}, {@see FunctionTypeNode}
 * and {@see StructTypeNode} are their own classes, built directly by {@see TypeParser} rather than through a factory
 * here, because each carries parts -- a field's name and type, a function's return type and binder, a struct's
 * fields -- that nothing here has anywhere to put.
 *
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
class TypeNode implements Stringable
{
    /**
     * @param list<self> $args The type's arguments, e.g. `T` and `U` in `map<T, U>`.
     */
    public function __construct(
        public readonly string $name,
        public readonly array $args,
        public readonly Span $location,
    ) {
    }

    #[Override]
    public function __toString(): string
    {
        return $this->args === []
            ? $this->name
            : sprintf('%s<%s>', $this->name, implode(', ', $this->args));
    }
}
