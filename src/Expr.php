<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Eventjet\Ausdruck\Parser\Span;
use Eventjet\Ausdruck\Parser\TypeHint;

use function is_string;

/**
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class Expr
{
    /**
     * @psalm-suppress UnusedConstructor
     */
    private function __construct()
    {
    }

    public static function eq(Expression $left, Expression $right): Eq
    {
        return new Eq($left, $right);
    }

    /**
     * @param Type | class-string | TypeHint $type
     */
    public static function get(string $name, TypeHint|Type|string $type, Span|null $location = null): Get
    {
        $type = is_string($type) ? Type::object($type) : $type;
        return new Get($name, $type, $location ?? self::dummySpan());
    }

    /**
     * @param string | int | float | bool | null | array<array-key, mixed> $value
     * @param Span|null $location
     * @return Literal
     */
    public static function literal(mixed $value, Span|null $location = null): Literal
    {
        return new Literal($value, $location ?? self::dummySpan());
    }

    /**
     * @param list<Expression> $elements
     */
    public static function listLiteral(array $elements, Span $location): ListLiteral
    {
        return new ListLiteral($elements, $location);
    }

    /**
     * @param list<Expression> $arguments
     */
    public static function call(Expression $target, string $name, Type $type, array $arguments, Span|null $location = null): Call
    {
        return new Call($target, $name, $type, $arguments, $location ?? self::dummySpan());
    }

    public static function or_(Expression $left, Expression $right): Or_
    {
        return new Or_($left, $right);
    }

    public static function and_(Expression $left, Expression $right): And_
    {
        return new And_($left, $right);
    }

    /**
     * @param list<string> $parameters
     */
    public static function lambda(Expression $body, array $parameters = [], Span|null $location = null): Expression
    {
        /** @infection-ignore-all We currently don't have a way to test this */
        $location ??= self::dummySpan();
        return new Lambda($body, $parameters, $location);
    }

    public static function subtract(Expression $minuend, Expression $subtrahend): Subtract
    {
        return new Subtract($minuend, $subtrahend);
    }

    public static function gt(Expression $left, Expression $right): Gt
    {
        return new Gt($left, $right);
    }

    public static function negative(Expression $expression, Span|null $location = null): Negative
    {
        return new Negative($expression, $location ?? self::dummySpan());
    }

    private static function dummySpan(): Span
    {
        /** @infection-ignore-all These dummy spans are just there to fill parameter lists */
        return Span::char(1, 1);
    }
}
