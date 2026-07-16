<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Parser;

use Eventjet\Ausdruck\Type;

/**
 * Every type the language spells itself, as opposed to the ones a consumer adds as aliases. This is the only list of
 * them, and everything the rest of the parser needs to know about one is an exhaustive match over this enum:
 * {@see Types::resolve()} turns each into a {@see Type}, {@see self::takesTypeArguments()} says which of them a `<` can
 * follow. Because both matches are exhaustive, a constructor can't be added here without the two being told about it,
 * so they can't drift apart the way two hand-kept lists would.
 *
 * The case names are the names as written, which is why some of them are PHP keywords.
 *
 * @internal
 * @psalm-internal Eventjet\Ausdruck\Parser
 */
enum TypeConstructor: string
{
    case Fn = 'fn';
    case String = 'string';
    case Int = 'int';
    case Float = 'float';
    case Bool = 'bool';
    case Any = 'any';
    case Map = 'map';
    case List = 'list';
    case Option = 'Option';
    case Some = 'Some';
    case None = 'None';
    /**
     * A struct is written as its fields—`{name: string}`—so it never appears as a name at all, and the empty string
     * stands in for the one it doesn't have.
     */
    case Struct = '';

    /**
     * Whether a `<` directly after this name opens a type argument list. It does for the generic constructors and for
     * nothing else, which is what lets `a:int < b:int` read as a comparison: see {@see TypeParser::parse()}, which has
     * to decide what the `<` is before there is a resolved type to ask. {@see self::Fn} takes arguments too, but in
     * parentheses, so no `<` ever follows it either.
     */
    public function takesTypeArguments(): bool
    {
        return match ($this) {
            self::Map, self::List, self::Option, self::Some => true,
            self::Fn, self::String, self::Int, self::Float, self::Bool, self::Any, self::None, self::Struct => false,
        };
    }
}
