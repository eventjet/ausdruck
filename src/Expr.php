<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use function is_string;

/**
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final readonly class Expr
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
     */
    public static function eq(Expression $left, Expression $right): Eq
    {
        return new Eq($left, $right);
    }

    /**
     * @template T
     * @param Type<T> | class-string<T> $type
     * @return Get<T>
     */
    public static function get(string $name, Type|string $type): Get
    {
        /** @phpstan-ignore-next-line False positive */
        return new Get($name, is_string($type) ? Type::object($type) : $type);
    }

    /**
     * @template T of string | int | float | bool | null | array<array-key, mixed>
     * @param T $value
     * @return Literal<T>
     */
    public static function literal(mixed $value): Literal
    {
        return new Literal($value);
    }

    /**
     * @template T
     * @param Expression<mixed> $target
     * @param list<Expression<mixed>> $arguments
     * @param Type<T> $type
     * @return Call<T>
     */
    public static function call(Expression $target, string $name, Type $type, array $arguments): Call
    {
        return new Call($target, $name, $type, $arguments);
    }

    /**
     * @param Expression<bool> $left
     * @param Expression<bool> $right
     */
    public static function or_(Expression $left, Expression $right): Or_
    {
        return new Or_($left, $right);
    }

    /**
     * @template T
     * @param Expression<T> $body
     * @param list<string> $parameters
     * @return Expression<callable(Scope): T>
     */
    public static function lambda(Expression $body, array $parameters = []): Expression
    {
        return new Lambda($body, $parameters);
    }

    /**
     * @template T of int | float
     * @param Expression<T> $minuend
     * @param Expression<T> $subtrahend
     * @return Subtract<T>
     */
    public static function subtract(Expression $minuend, Expression $subtrahend): Subtract
    {
        return new Subtract($minuend, $subtrahend);
    }

    /**
     * @template T of int | float
     * @param Expression<T> $left
     * @param Expression<T> $right
     * @return Gt<T>
     */
    public static function gt(Expression $left, Expression $right): Gt
    {
        return new Gt($left, $right);
    }
}
