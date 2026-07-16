<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

/**
 * The six comparison operators, each paired with the rule that decides it: `===` and `!==` compare any two values of
 * the same type by deep structural equality ({@see ValueEquality}), `>`, `<`, `>=` and `<=` compare two numbers. Which
 * operands an operator will accept is {@see Expr}'s business, the one place nodes are built and their types checked;
 * this only says how a pair of operands that already type-check is decided.
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
