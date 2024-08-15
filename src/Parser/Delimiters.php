<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Parser;

/**
 * @psalm-internal Eventjet\Ausdruck\Parser
 */
enum Delimiters
{
    case CurlyBraces;
    case AngleBrackets;

    public function start(): string
    {
        return match ($this) {
            self::CurlyBraces => '{',
            self::AngleBrackets => '<',
        };
    }

    public function end(): string
    {
        return match ($this) {
            self::CurlyBraces => '}',
            self::AngleBrackets => '>',
        };
    }
}
