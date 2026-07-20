<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use const PHP_INT_MIN;

/**
 * The int arithmetic whose result may not exist in int, answered rather than computed: {@see Arithmetic} sets out why an
 * operator decides that before widening rather than after.
 *
 * PHP_INT_MIN is one further from zero than PHP_INT_MAX, so negating it is the one negation with no int result. Three
 * operators meet it, and all three are negating when they do: unary minus ({@see Negative}), multiplying by -1
 * ({@see Multiply}) and dividing by -1 ({@see Divide}). Stating it once here is what saves the three from having to
 * agree about it.
 *
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class IntArithmetic
{
    /**
     * @psalm-suppress UnusedConstructor
     */
    private function __construct()
    {
    }

    /**
     * The negation of $value, or null if int has none.
     */
    public static function negate(int $value): int|null
    {
        return $value === PHP_INT_MIN ? null : -$value;
    }
}
