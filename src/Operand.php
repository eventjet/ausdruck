<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use function get_debug_type;
use function is_bool;
use function is_float;
use function is_int;
use function is_object;
use function sprintf;

/**
 * Narrows the mixed an expression hands back—from {@see Expression::evaluate()}, or from {@see Literal::value()}—to the
 * concrete type an operator needs to apply its PHP counterpart.
 *
 * These are not type checks. {@see Expr} has already rejected operands of the wrong type, and everything that reads a
 * value from a {@see Scope} ({@see Get}, {@see Call}) asserts it against its declared type, so none of these can fail
 * for an expression that was built through {@see Expr}. They exist so the nodes can go from mixed to int, float, bool or
 * object without a cast. Anything thrown here is a bug in this library, not in the expression that was evaluated.
 *
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class Operand
{
    /**
     * @psalm-suppress UnusedConstructor
     */
    private function __construct()
    {
    }

    /**
     * @infection-ignore-all Unreachable; see the class docblock.
     */
    public static function bool(mixed $value): bool
    {
        return is_bool($value)
            ? $value
            : throw new EvaluationError(sprintf('Expected a boolean operand, got %s', get_debug_type($value)));
    }

    /**
     * @infection-ignore-all Unreachable; see the class docblock.
     */
    public static function number(mixed $value): int|float
    {
        return is_int($value) || is_float($value)
            ? $value
            : throw new EvaluationError(sprintf('Expected an int or float operand, got %s', get_debug_type($value)));
    }

    /**
     * @infection-ignore-all Unreachable; see the class docblock.
     */
    public static function struct(mixed $value): object
    {
        return is_object($value)
            ? $value
            : throw new EvaluationError(sprintf('Expected a struct operand, got %s', get_debug_type($value)));
    }
}
