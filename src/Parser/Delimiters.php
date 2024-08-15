<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Parser;

enum Delimiters
{
    case Parentheses;
    case Brackets;
    case CurlyBraces;
    case AngleBrackets;

    public function start(): string
    {
        return match ($this) {
            self::Parentheses => '(',
            self::Brackets => '[',
            self::CurlyBraces => '{',
            self::AngleBrackets => '<',
        };
    }

    public function end(): string
    {
        return match ($this) {
            self::Parentheses => ')',
            self::Brackets => ']',
            self::CurlyBraces => '}',
            self::AngleBrackets => '>',
        };
    }
}
