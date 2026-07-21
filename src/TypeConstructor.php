<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use function sprintf;

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
 * This lives in {@see Type}'s own namespace, not the parser's: {@see Type::var()} rejects a name the language spells
 * itself the same way {@see Parser\TypeResolution} does, and a type is a thing the parser depends on, not the other
 * way around.
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
     * The message {@see Type::var()}, {@see Type::alias()}, and {@see Parser\TypeResolution}'s binder check reject a
     * reserved name with, worded once here so the three can't drift apart. $usage is what the name was being claimed
     * as -- "a type variable" for the two binder-facing callers, "an alias" for {@see Type::alias()} -- since the two
     * reservations exist for different reasons and a shared message that named only one of them would be wrong for
     * the other.
     */
    public static function reservedNameMessage(string $name, string $usage = 'a type variable'): string
    {
        return sprintf('%s can\'t be %s: it is a type of its own', $name, $usage);
    }

    /**
     * Whether $name is off-limits to a type variable or an alias: every name a case above already spells, plus two
     * that aren't a case here and never will be, because neither is ever written as a name at all -- `fn` is its own
     * node shape, and `Struct` is the marker {@see Type::struct()} names a struct type with internally, a struct
     * itself being written only as its fields. `never`, the bottom type {@see Type::fromValue()} infers for an empty
     * list or map, is the third: nothing ever writes it either, only infers it. A name {@see Type} gives special
     * meaning to is exactly as unavailable to a variable or an alias as one the language does spell, or
     * {@see Type::var('Struct')} would build a variable that {@see Type::isStruct()} and every other name-based check
     * then misreads as an actual struct.
     */
    public static function isReservedName(string $name): bool
    {
        return $name === 'fn' || $name === 'Struct' || $name === 'never' || self::tryFrom($name) !== null;
    }

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
