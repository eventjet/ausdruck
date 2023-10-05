<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Parser;

use Stringable;
use function implode;
use function sprintf;

/**
 * @internal
 */
final readonly class TypeNode implements Stringable
{
    /**
     * @param list<self> $args
     */
    public function __construct(public string $name, public array $args = [])
    {
    }

    public function __toString(): string
    {
        return $this->args === []
            ? $this->name
            : sprintf('%s<%s>', $this->name, implode(', ', $this->args));
    }
}
