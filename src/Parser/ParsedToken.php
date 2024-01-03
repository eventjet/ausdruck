<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Parser;

final class ParsedToken
{
    /**
     * @param Token | string | Literal<string | int | float> $token
     */
    public function __construct(
        public readonly Token|string|Literal $token,
        public readonly int $line,
        public readonly int $column,
    ) {
    }
}
