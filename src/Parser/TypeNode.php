<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Parser;

use Eventjet\Ausdruck\Type;
use Override;
use Stringable;

use function array_pop;
use function assert;
use function implode;
use function sprintf;

/**
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class TypeNode implements Stringable
{
    /**
     * @param list<self> $args
     * @param Delimiters | 'kv' | 'fn' $delimiters
     * @param list<self> $typeParameters The type variables a function type binds, which are only ever the names
     *     themselves. They are nodes rather than strings so that each one carries the span an error about it points
     *     at; see {@see Types::resolve()}.
     */
    public function __construct(
        public readonly string $name,
        public readonly array $args,
        public readonly Span $location,
        public readonly Delimiters|string $delimiters = Delimiters::AngleBrackets,
        public readonly array $typeParameters = [],
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
     * A function type keeps its return type as the last of its args, the way {@see Type::func()} does, so that
     * {@see Types::resolve()} has one list to walk.
     *
     * @param list<self> $parameters
     * @param list<self> $typeParameters
     */
    public static function function(array $parameters, self $returnType, array $typeParameters, Span $location): self
    {
        return new self('fn', [...$parameters, $returnType], $location, 'fn', $typeParameters);
    }

    #[Override]
    public function __toString(): string
    {
        if ($this->delimiters === 'kv') {
            return sprintf('%s: %s', $this->args[0], $this->args[1]);
        }
        if ($this->delimiters === 'fn') {
            $parameters = $this->args;
            // A function type is written as its parameters and its return type, and the return type is the last of the
            // args: {@see self::function()} puts it there, so there is always one to take back off.
            $returnType = array_pop($parameters);
            assert($returnType !== null);
            return sprintf(
                'fn%s(%s) -> %s',
                $this->typeParameters === [] ? '' : sprintf('<%s>', implode(', ', $this->typeParameters)),
                implode(', ', $parameters),
                $returnType,
            );
        }
        return $this->args === []
            ? $this->name
            : sprintf('%s%s%s%s', $this->name, $this->delimiters->start(), implode(', ', $this->args), $this->delimiters->end());
    }
}
