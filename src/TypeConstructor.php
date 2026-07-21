<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

/**
 * Every type the language spells itself with a name of its own that's written in angle brackets or bare -- `fn` is
 * its own node shape ({@see Parser\FunctionTypeNode}) rather than a case here, since {@see Parser\TypeResolution::resolve()}
 * dispatches it before a name is ever looked at, and a struct has no name of its own either, since it's always
 * written as its fields. How many type arguments each case takes is stated once, in {@see self::typeArgumentCount()}.
 * Both of the questions the parser asks about a constructor are answers to that one number:
 * {@see Parser\TypeResolution::checkArity()} checks the count it was given against it, and {@see Parser\TypeParser::parse()}
 * asks whether it is greater than zero before committing a `<` to being a type argument list. Deriving them means a
 * constructor can't be given an arity in one place and checked against a different one in another—the exhaustive
 * match forces a new case to be given a count, and there is no second count for it to disagree with.
 *
 * The case names are the names as written, which is why some of them are PHP keywords.
 *
 * This lives in {@see Type}'s own namespace, not the parser's: a type is a thing the parser depends on, not the other
 * way around.
 *
 * A case here is also the one genuine restriction on a type variable's name: within the `fn<...>` binder that
 * declares it, a name a case here already spells could never again be written as the type it names, only as the
 * variable shadowing it, so {@see Parser\TypeResolution::checkTypeVariable()} rejects one. That is a rule about what a
 * reader can tell apart in written syntax, which is the one place it's enforced -- {@see Type::var()} and
 * {@see Type::alias()} take whatever name they're given, since two PHP calls picking `Type::var('int')` and
 * `Type::int()` are never ambiguous about which is which the way two occurrences of the bare word `int` in one
 * signature would be.
 *
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
enum TypeConstructor: string
{
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
     * How many type arguments this constructor is written with in angle brackets.
     *
     * @return int<0, max>
     */
    public function typeArgumentCount(): int
    {
        return match ($this) {
            self::Map => 2,
            self::List, self::Option, self::Some => 1,
            self::String, self::Int, self::Float, self::Bool, self::Any, self::None => 0,
        };
    }
}
