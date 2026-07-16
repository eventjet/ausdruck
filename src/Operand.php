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
 * {@see self::int()} is the one exception to all of this: int overflow reaches it honestly — see there.
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
     * Unlike its siblings, this narrowing is reachable for a well-typed expression: int is the one operand type PHP's
     * arithmetic doesn't close over. An int operation that overflows evaluates to a float, so an int-typed operand can
     * arrive here as one — `(a:int + b:int) / c:int` with a sum past PHP_INT_MAX — and a value that has already left
     * int has no int quotient or remainder to give. Throwing is the designed answer to that overflow, not a guard that
     * can't fire.
     */
    public static function int(mixed $value): int
    {
        return is_int($value)
            ? $value
            : throw new EvaluationError(sprintf('Expected an int operand, got %s', get_debug_type($value)));
    }

    /**
     * @infection-ignore-all Unreachable; see the class docblock.
     */
    public static function float(mixed $value): float
    {
        return is_float($value)
            ? $value
            : throw new EvaluationError(sprintf('Expected a float operand, got %s', get_debug_type($value)));
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
