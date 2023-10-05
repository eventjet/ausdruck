<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Parser;

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
    case Pipe = '|';
    case Comma = ',';
    case Colon = ':';
    case Minus = '-';

    /**
     * @param Token | string | Literal<string | int | float> $token
     */
    public static function print(self|string|Literal $token): string
    {
        return $token instanceof self ? $token->value : (string)$token;
    }
}
