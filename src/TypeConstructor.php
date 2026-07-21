<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use function sprintf;

/**
 * Every type the language spells itself, as opposed to the ones a consumer adds as aliases -- plus {@see self::Struct}
 * and {@see self::Never}, which it never spells at all, but which still have to be here: {@see Type} uses both names
 * internally (a struct's own marker, and the bottom type inferred for an empty list or map), and a name {@see Type}
 * gives special meaning to is exactly as unavailable to a variable or an alias as one the language does spell, or
 * {@see Type::var('Struct')} would build a variable that {@see Type::isStruct()} and every other name-based check then
 * misreads as an actual struct. This is the only list of them, and how many type arguments each one takes is stated
 * once, in {@see self::typeArgumentCount()}. Both of the questions the parser asks about a constructor are answers to
 * that one number: {@see Parser\TypeResolution::checkArity()} checks the count it was given against it, and
 * {@see Parser\TypeParser::parse()} asks whether it is greater than zero before committing a `<` to being a type
 * argument list. Deriving them means a constructor can't be given an arity in one place and checked against a
 * different one in another—the exhaustive match forces a new case to be given a count, and there is no second count
 * for it to disagree with.
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
    case Struct = 'Struct';
    case Never = 'never';

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
     * How many type arguments this constructor is written with in angle brackets. Null means it is not written with
     * angle brackets at all: {@see self::Fn} takes its argument types in parentheses, so it has no count for a `<` to
     * be checked against. {@see self::Struct} and {@see self::Never} are never written as a name at all -- a struct is
     * written as its fields, and the bottom type is never written, only inferred -- but a name that isn't reachable
     * still needs an arity, or the exhaustive match in {@see self::typeArgumentCount()} itself would have to special-case
     * the two cases that exist purely to be reserved.
     *
     * @return int<0, max>|null
     */
    public function typeArgumentCount(): int|null
    {
        return match ($this) {
            self::Map => 2,
            self::List, self::Option, self::Some => 1,
            self::String, self::Int, self::Float, self::Bool, self::Any, self::None, self::Struct, self::Never => 0,
            self::Fn => null,
        };
    }
}
