<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Parser;

final class Span
{
    /**
     * @param positive-int $startLine
     * @param positive-int $startColumn
     * @param positive-int $endLine
     * @param positive-int $endColumn
     */
    public function __construct(
        public readonly int $startLine,
        public readonly int $startColumn,
        public readonly int $endLine,
        public readonly int $endColumn,
    ) {
    }

    /**
     * @param positive-int $line
     * @param positive-int $column
     */
    public static function char(int $line, int $column): self
    {
        return new self($line, $column, $line, $column);
    }

    public function to(self $end): self
    {
        return new self(
            $this->startLine,
            $this->startColumn,
            $end->endLine,
            $end->endColumn,
        );
    }
}
