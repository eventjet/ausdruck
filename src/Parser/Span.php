<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Parser;

final class Span
{
    public function __construct(
        public readonly int $startLine,
        public readonly int $startColumn,
        public readonly int $endLine,
        public readonly int $endColumn,
    ) {
    }

    public static function char(int $line, int $column): self
    {
        return new self($line, $column, $line, $column);
    }
}
