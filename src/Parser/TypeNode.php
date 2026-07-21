<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Parser;

use Override;
use Stringable;

use function implode;
use function sprintf;

/**
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class TypeNode implements Stringable
{
    /**
     * @param list<self> $args The type's arguments. For a function type, these are its parameters -- see
     *     {@see self::function()} -- and its return type is $returnType instead, not one of them.
     * @param Delimiters | 'kv' $delimiters
     * @param list<self> $typeParameters The type variables a function type binds, which are only ever the names
     *     themselves. They are nodes rather than strings so that each one carries the span an error about it points
     *     at; see {@see Types::resolve()}. Meaningless where $returnType is null.
     * @param self|null $returnType The return type of a function type, and what marks this node as one: every other
     *     shape leaves it null. See {@see self::function()} and {@see TypeResolution::resolveFunction()}.
     */
    public function __construct(
        public readonly string $name,
        public readonly array $args,
        public readonly Span $location,
        public readonly Delimiters|string $delimiters = Delimiters::AngleBrackets,
        public readonly array $typeParameters = [],
        public readonly self|null $returnType = null,
    ) {
    }

    /**
     * @param list<self> $fields
     */
    public static function struct(array $fields, Span $location): self
    {
        return new self('', $fields, $location, Delimiters::CurlyBraces);
    }

    public static function keyValue(self $key, self $value): self
    {
        return new self('', [$key, $value], $key->location->to($value->location), 'kv');
    }

    /**
     * @param list<self> $parameters
     * @param list<self> $typeParameters
     */
    public static function function(array $parameters, self $returnType, array $typeParameters, Span $location): self
    {
        return new self('fn', $parameters, $location, typeParameters: $typeParameters, returnType: $returnType);
    }

    #[Override]
    public function __toString(): string
    {
        if ($this->delimiters === 'kv') {
            return sprintf('%s: %s', $this->args[0], $this->args[1]);
        }
        if ($this->returnType !== null) {
            return sprintf(
                'fn%s(%s) -> %s',
                $this->typeParameters === [] ? '' : sprintf('<%s>', implode(', ', $this->typeParameters)),
                implode(', ', $this->args),
                $this->returnType,
            );
        }
        return $this->args === []
            ? $this->name
            : sprintf('%s%s%s%s', $this->name, $this->delimiters->start(), implode(', ', $this->args), $this->delimiters->end());
    }
}
