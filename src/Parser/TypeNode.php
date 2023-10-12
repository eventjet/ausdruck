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
     */
    public function __construct(public readonly string $name, public readonly array $args = [])
    {
    }

    public function __toString(): string
    {
        return $this->args === []
            ? $this->name
            : sprintf('%s<%s>', $this->name, implode(', ', $this->args));
    }
}
