<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Parser;

use Stringable;

use function is_string;
use function sprintf;

/**
 * @template-covariant T of string | int | float
 * @internal
 */
final readonly class Literal implements Stringable
{
    /**
     * @param T $value
     */
    public function __construct(public string|int|float $value)
    {
    }

    public function __toString(): string
    {
        if (is_string($this->value)) {
            return sprintf('"%s"', $this->value);
        }
        return (string)$this->value;
    }
}
