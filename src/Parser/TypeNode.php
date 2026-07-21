<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Parser;

use Override;
use Stringable;

use function implode;
use function sprintf;

/**
 * A name applied to type arguments, e.g. `map<T, U>` or a bare `int`. {@see self::struct()} builds the one other
 * shape that reads this way -- a struct is its fields between `{ }` rather than a name between `< >` -- and
 * {@see self::keyValue()} and {@see self::function()} build the two shapes that don't: {@see FieldTypeNode} and
 * {@see FunctionTypeNode} are their own classes because a field and a function type carry parts nothing else does,
 * not a delimiter away from this one.
 *
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
class TypeNode implements Stringable
{
    /**
     * @param list<self> $args The type's arguments, e.g. `T` and `U` in `map<T, U>`, or a struct's fields.
     */
    public function __construct(
        public readonly string $name,
        public readonly array $args,
        public readonly Span $location,
        public readonly Delimiters $delimiters = Delimiters::AngleBrackets,
    ) {
    }

    /**
     * @param list<self> $fields
     */
    public static function struct(array $fields, Span $location): self
    {
        return new self('', $fields, $location, Delimiters::CurlyBraces);
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
            : sprintf('%s%s%s%s', $this->name, $this->delimiters->start(), implode(', ', $this->args), $this->delimiters->end());
    }
}
