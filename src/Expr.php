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

    /**
     * @template T
     * @param Expression<T> $left
     * @param Expression<T> $right
     * @return Expression<bool>
     */
    public static function eq(Expression $left, Expression $right): Expression
    {
        return Call::infix('===', $left, $right, Type::bool());
    }

    /**
     * @template T
     * @param Type<T> | class-string<T> | TypeHint<T> $type
     * @return Get<T>
     */
    public static function get(string $name, TypeHint|Type|string $type, Span|null $location = null): Get
    {
        /** @phpstan-ignore-next-line Must be a PHPStan bug */
        $type = is_string($type) ? Type::object($type) : $type;
        /** @phpstan-ignore-next-line False positive */
        return new Get($name, $type, $location ?? self::dummySpan());
    }

    /**
     * @template T of string | int | float | bool | null | array<array-key, mixed>
     * @param T $value
     * @return Literal<T>
     */
    public static function literal(mixed $value, Span|null $location = null): Literal
    {
        return new Literal($value, $location ?? self::dummySpan());
    }

    /**
     * @template T
     * @param list<Expression<T>> $elements
     * @return ListLiteral<T>
     */
    public static function listLiteral(array $elements, Span $location): ListLiteral
    {
        return new ListLiteral($elements, $location);
    }

    /**
     * @template T
     * @param Expression<mixed> $target
     * @param list<Expression<mixed>> $arguments
     * @param Type<T> $type
     * @return Call<T>
     */
    public static function call(Expression $target, string $name, Type $type, array $arguments, Span|null $location = null): Call
    {
        return new Call($target, $name, $type, $arguments, $location ?? self::dummySpan());
    }

    /**
     * @param Expression<bool> $left
     * @param Expression<bool> $right
     * @return Expression<bool>
     */
    public static function or_(Expression $left, Expression $right): Expression
    {
        return Call::infix('||', $left, $right, Type::bool());
    }

    /**
     * @template T
     * @param Expression<T> $body
     * @param list<string> $parameters
     * @return Expression<callable(Scope): T>
     */
    public static function lambda(Expression $body, array $parameters = [], Span|null $location = null): Expression
    {
        /** @infection-ignore-all We currently don't have a way to test this */
        $location ??= self::dummySpan();
        return new Lambda($body, $parameters, $location);
    }

    /**
     * @template T of int | float
     * @param Expression<T> $minuend
     * @param Expression<T> $subtrahend
     * @return Expression<T>
     */
    public static function subtract(Expression $minuend, Expression $subtrahend): Expression
    {
        return Call::infix('-', $minuend, $subtrahend, $minuend->getType());
    }

    /**
     * @template T of int | float
     * @param Expression<T> $left
     * @param Expression<T> $right
     * @return Expression<bool>
     */
    public static function gt(Expression $left, Expression $right): Expression
    {
        return Call::infix('>', $left, $right, Type::bool());
    }

    /**
     * @template T of int | float
     * @param Expression<T> $expression
     * @return Expression<T>
     */
    public static function negative(Expression $expression, Span|null $location = null): Expression
    {
        return Call::prefix('-', $expression, $expression->getType(), $location ?? self::dummySpan());
    }

    private static function dummySpan(): Span
    {
        /** @infection-ignore-all These dummy spans are just there to fill parameter lists */
        return Span::char(1, 1);
    }
}
