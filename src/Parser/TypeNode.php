<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Parser;

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
     * @param list<self> $args
     * @param Delimiters | 'kv' $delimiters
     */
    public function __construct(
        public readonly string $name,
        public readonly array $args,
        public readonly Span $location,
        public readonly Delimiters|string $delimiters = Delimiters::AngleBrackets,
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

    public function __toString(): string
    {
        if ($this->delimiters === 'kv') {
            return sprintf('%s: %s', $this->args[0], $this->args[1]);
        }
        return $this->args === []
            ? $this->name
            : sprintf('%s%s%s%s', $this->name, $this->delimiters->start(), implode(', ', $this->args), $this->delimiters->end());
    }
}
