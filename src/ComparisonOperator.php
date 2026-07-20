<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

/**
 * The six comparison operators, each paired with the rule that decides it: `===` and `!==` compare any two values of
 * the same type by deep structural equality ({@see ValueEquality}), `>`, `<`, `>=` and `<=` compare two numbers. This
 * only says how a pair of operands that already type-checks is decided; which operands an operator will accept is
 * {@see Expr::checkComparison()}'s business. Keeping that there rather than here is what holds the dependencies one
 * way: this enum needs to know about runtime values and nothing else, while deciding what an operator accepts means
 * knowing about {@see Expression}, {@see Type} and the errors raised for a mismatch.
 *
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
enum ComparisonOperator: string
{
    case Equals = '===';
    case NotEquals = '!==';
    case GreaterThan = '>';
    case LessThan = '<';
    case GreaterThanOrEqual = '>=';
    case LessThanOrEqual = '<=';

    public function compare(mixed $left, mixed $right): bool
    {
        return match ($this) {
            self::Equals => ValueEquality::equals($left, $right),
            self::NotEquals => !ValueEquality::equals($left, $right),
            self::GreaterThan => Operand::number($left) > Operand::number($right),
            self::LessThan => Operand::number($left) < Operand::number($right),
            self::GreaterThanOrEqual => Operand::number($left) >= Operand::number($right),
            self::LessThanOrEqual => Operand::number($left) <= Operand::number($right),
        };
    }
}
