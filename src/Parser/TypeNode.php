<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Parser;

use Override;
use Stringable;

use function implode;
use function sprintf;

/**
 * A name applied to type arguments, e.g. `map<T, U>` or a bare `int`. {@see self::keyValue()}, {@see self::function()}
 * and {@see self::struct()} build the three shapes that don't read this way: {@see FieldTypeNode},
 * {@see FunctionTypeNode} and {@see StructTypeNode} are their own classes because each carries parts -- a field's name
 * and type, a function's return type and binder, a struct's fields -- that nothing here has anywhere to put.
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

    /**
     * @param list<FieldTypeNode> $fields
     */
    public static function struct(array $fields, Span $location): StructTypeNode
    {
        return new StructTypeNode($fields, $location);
    }

    public static function keyValue(self $key, self $value): FieldTypeNode
    {
        return new FieldTypeNode($key, $value);
    }

    /**
     * @param list<self> $parameters
     * @param list<self> $typeParameters
     */
    public static function function(array $parameters, self $returnType, array $typeParameters, Span $location): FunctionTypeNode
    {
        return new FunctionTypeNode($parameters, $returnType, $typeParameters, $location);
    }

    #[Override]
    public function __toString(): string
    {
        return $this->args === []
            ? $this->name
            : sprintf('%s<%s>', $this->name, implode(', ', $this->args));
    }
}
