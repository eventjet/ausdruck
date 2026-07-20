<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Parser;

final class ParsedToken
{
    /**
     * @param Token | string | Literal<string | int | float | bool> $token
     * @param Span $location Where the token is written, as wide as it is written. Handed in by {@see Tokenizer},
     *     which is scanning the source and is the only thing that knows: a token does not remember its spelling, so
     *     the extent cannot be worked back out of it. `007` and `1.50` are the same tokens as `7` and `1.5`, and
     *     measuring how they print would put the end of the first one two columns short of where it is written.
     */
    public function __construct(
        public readonly Token|string|Literal $token,
        private readonly Span $location,
    ) {
    }

    public function location(): Span
    {
        return $this->location;
    }
}
