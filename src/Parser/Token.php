<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Parser;

/**
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
enum Token: string
{
    case Dot = '.';
    case TripleEquals = '===';
    case Quote = '"';
    case OpenParen = '(';
    case CloseParen = ')';
    case OpenBracket = '[';
    case CloseBracket = ']';
    case OpenAngle = '<';
    case CloseAngle = '>';
    case Or = '||';
    case And = '&&';
    case Pipe = '|';
    case Comma = ',';
    case Colon = ':';
    case Minus = '-';
    case Arrow = '->';

    /**
     * @param Token | string | Literal<string | int | float> $token
     */
    public static function print(self|string|Literal $token): string
    {
        return $token instanceof self ? $token->value : (string)$token;
    }
}
