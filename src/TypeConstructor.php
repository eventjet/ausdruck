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
 * This lives in {@see Type}'s own namespace, not the parser's: a type is a thing the parser depends on, not the other
 * way around.
 *
 * A case here is also one of the two restrictions {@see self::isReservedName()} enforces: within the `fn<...>` binder
 * that declares it, a name a case here already spells could never again be written as the type it names, only as the
 * variable shadowing it, so {@see Parser\TypeResolution::checkTypeVariable()} rejects one when a signature is written
 * as a type string. {@see Type::var()} and {@see Type::alias()} reject the same names from the PHP builder side too,
 * since {@see Type::__toString()} turns either one back into the written syntax the parser would then reject:
 * `parse(str($type)) === $type` is a hard invariant this project holds throughout. `fn` is the other restriction: not
 * a case here, since a function type is its own node shape ({@see Parser\FunctionTypeNode}), but still the one bare
 * word {@see Parser\TypeParser::parse()} always reads as introducing one, so a variable or alias named `fn` would
 * print as a bare `fn` no parser could ever read back as anything else.
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

    /**
     * The message {@see Type::var()} and {@see Type::alias()} reject a reserved name with, worded once here so the
     * two can't drift apart. $usage is what the name was being claimed as -- "a type variable" for the former, "an
     * alias" for the latter -- since the two reservations exist for different reasons and a shared message that
     * named only one of them would be wrong for the other.
     */
    public static function reservedNameMessage(string $name, string $usage = 'a type variable'): string
    {
        return sprintf('%s can\'t be %s: it is a type of its own', $name, $usage);
    }

    /**
     * Whether $name is off-limits to a type variable or an alias built through {@see Type::var()} or
     * {@see Type::alias()}: every name a case above already spells, plus `fn`, which isn't one of them and never will
     * be -- see this enum's own class doc for why both are reserved. `Struct` and `never` are deliberately not
     * reserved: neither is ever written as a name in this grammar either, a struct only as its fields and `never` not
     * at all, so a variable or alias can be named either one without colliding with anything a reader could confuse
     * it for -- see the fixtures showing both are ordinary names.
     */
    public static function isReservedName(string $name): bool
    {
        return $name === 'fn' || self::tryFrom($name) !== null;
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
            self::List => 1,
            self::String, self::Int, self::Float, self::Bool, self::Any => 0,
        };
    }
}
