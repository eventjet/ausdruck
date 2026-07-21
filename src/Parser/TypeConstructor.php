<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Parser;

use Eventjet\Ausdruck\Type;

/**
 * Every type the language spells itself, as opposed to the ones a consumer adds as aliases. This is the only list of
 * them, and how many type arguments each one takes is stated once, in {@see self::typeArgumentCount()}. Both of the
 * questions the parser asks about a constructor are answers to that one number: {@see Types::resolve()} checks the
 * count it was given against it, and {@see self::takesTypeArguments()} asks whether it is greater than zero. Deriving
 * them means a constructor can't be given an arity in one place and checked against a different one in another—the
 * exhaustive match forces a new case to be given a count, and there is no second count for it to disagree with.
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
     * How many type arguments this constructor is written with in angle brackets. Null means it is not written with
     * angle brackets at all: {@see self::Fn} takes its argument types in parentheses, and {@see self::Struct} is
     * written as its fields, so neither has a count for a `<` to be checked against.
     *
     * @return int<0, max>|null
     */
    public function typeArgumentCount(): int|null
    {
        return match ($this) {
            self::Map => 2,
            self::List, self::Option, self::Some => 1,
            self::String, self::Int, self::Float, self::Bool, self::Any, self::None => 0,
            self::Fn, self::Struct => null,
        };
    }

    /**
     * Whether a `<` directly after this name opens a type argument list. It does for the constructors that take at
     * least one, and for nothing else, which is what lets `a:int < b:int` read as a comparison: see
     * {@see TypeParser::type()}, which has to decide what the `<` is before there is a resolved type to ask.
     */
    public function takesTypeArguments(): bool
    {
        return ($this->typeArgumentCount() ?? 0) > 0;
    }
}
