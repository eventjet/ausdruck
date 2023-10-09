<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Parser;

/**
 * @template-covariant V
 */
final readonly class Span
{
    /**
     * @param V $value
     */
    public function __construct(
        public mixed $value,
        public int $startLine,
        public int $startColumn,
        public int $endLine,
        public int $endColumn,
    ) {
    }
}
