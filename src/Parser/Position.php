<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Parser;

/**
 * Where a single character sits in the source text.
 *
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class Position
{
    /**
     * @param positive-int $line
     * @param positive-int $column
     */
    public function __construct(public readonly int $line, public readonly int $column)
    {
    }

    /**
     * The extent of the one character at this position.
     */
    public function span(): Span
    {
        return Span::char($this->line, $this->column);
    }
}
