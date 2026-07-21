<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Eventjet\Ausdruck\Parser\Token;

/**
 * The six comparison operators, each paired with the token that spells it and the rule that decides it: `===` and `!==`
 * compare any two values of the same type by deep structural equality ({@see ValueEquality}), `>`, `<`, `>=` and `<=`
 * compare two numbers. This only says how an operator is written and how a pair of operands that already type-checks is
 * decided; which operands an operator will accept is {@see Expr::checkComparison()}'s business. Keeping that there
 * rather than here is what holds the dependencies one way: deciding what an operator accepts means knowing about
 * {@see Expression}, {@see Type} and the errors raised for a mismatch, none of which this needs.
 *
 * The enum is deliberately not backed. An operator's spelling is its {@see self::token()}'s, so `===` is written in one
 * place, and a backing value would be a second spelling for that one to drift from.
 *
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
enum ComparisonOperator
{
    case Equals;
    case NotEquals;
    case GreaterThan;
    case LessThan;
    case GreaterThanOrEqual;
    case LessThanOrEqual;

    /**
     * The token this operator is spelled with, and so — by way of {@see BinaryOperator::symbol()} — the spelling it
     * prints as and the level it binds at. The two angle tokens are named for their other job, delimiting a type
     * parameter list like `list<int>`; the lexer emits the same token for both, so these are the `<` and `>` the
     * parser reads as comparisons.
     */
    public function token(): Token
    {
        return match ($this) {
            self::Equals => Token::TripleEquals,
            self::NotEquals => Token::NotEquals,
            self::GreaterThan => Token::CloseAngle,
            self::LessThan => Token::OpenAngle,
            self::GreaterThanOrEqual => Token::GreaterThanEquals,
            self::LessThanOrEqual => Token::LessThanEquals,
        };
    }

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
