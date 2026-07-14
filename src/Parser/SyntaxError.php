<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Parser;

use RuntimeException;
use Throwable;

use function is_string;
use function sprintf;

final class SyntaxError extends RuntimeException
{
    public function __construct(
        string $message = '',
        int $code = 0,
        Throwable|null $previous = null,
        public readonly Span|null $location = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public static function create(string $message, Span $location): self
    {
        return new self($message, location: $location);
    }

    /**
     * A token in a position where the grammar has nothing left to do with it. Identifiers are named as such, because
     * printing them bare would make `true false` read as if `false` were a symbol.
     */
    public static function unexpectedToken(ParsedToken $token): self
    {
        return self::create(
            is_string($token->token)
                ? sprintf('Unexpected identifier %s', $token->token)
                : sprintf('Unexpected %s', Token::print($token->token)),
            $token->location(),
        );
    }
}
