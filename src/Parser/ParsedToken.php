<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Parser;

use function strlen;

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

    public function span(): Span
    {
        $str = $this->token instanceof Token ? $this->token->value : (string)$this->token;
        return new Span($this->line, $this->column, $this->line, $this->column + strlen($str) - 1);
    }
}
