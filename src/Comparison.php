<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Eventjet\Ausdruck\Parser\Token;
use Override;

/**
 * A comparison of two operands by one of the six comparison operators. Everything a comparison does—printing,
 * evaluating, type-checking, comparing itself to another expression—is the same whichever operator it holds, and lives
 * here or in {@see BinaryOperator}; the operator only supplies the symbol and the rule that decides it, both carried by
 * {@see ComparisonOperator}.
 *
 * Four of the six operators are plain instances of this class, which is why it isn't abstract. `===` and `>` are the
 * exceptions: each keeps a final subclass of its own ({@see Eq}, {@see Gt}), only because {@see Expression::eq()} and
 * {@see Expression::gt()} have declared those return types since before this class existed—see {@see Eq} for why that
 * pins them.
 *
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
class Comparison extends BinaryOperator
{
    public function __construct(
        private readonly ComparisonOperator $operator,
        Expression $left,
        Expression $right,
    ) {
        parent::__construct($left, $right);
    }

    /**
     * The six operators are spelled by six tokens, and the spelling {@see BinaryOperator::symbol()} prints comes from
     * the token rather than from {@see ComparisonOperator}'s backing value, so the printer and the lexer can't drift.
     * The two angle tokens are named for their other job, delimiting a type parameter list like `list<int>`; the lexer
     * emits the same token for both, so these are the `<` and `>` the parser reads here.
     */
    #[Override]
    final public function token(): Token
    {
        return match ($this->operator) {
            ComparisonOperator::Equals => Token::TripleEquals,
            ComparisonOperator::NotEquals => Token::NotEquals,
            ComparisonOperator::GreaterThan => Token::CloseAngle,
            ComparisonOperator::LessThan => Token::OpenAngle,
            ComparisonOperator::GreaterThanOrEqual => Token::GreaterThanEquals,
            ComparisonOperator::LessThanOrEqual => Token::LessThanEquals,
        };
    }

    #[Override]
    public function evaluate(Scope $scope): bool
    {
        return $this->operator->compare($this->left->evaluate($scope), $this->right->evaluate($scope));
    }

    /**
     * Which operator is held is part of a comparison's identity, and it is the one piece of that identity
     * {@see BinaryOperator::equals()} can't see: the four operators without a subclass are all the same class, so
     * `a < b` and `a >= b` would otherwise compare equal. Matching on {@see self} rather than `static` is what lets
     * {@see Eq} equal a plain Comparison holding {@see ComparisonOperator::Equals}—they are the same expression, and
     * the subclass exists only to keep a return type from widening.
     */
    #[Override]
    public function equals(Expression $other): bool
    {
        return $other instanceof self
            && $this->operator === $other->operator
            && $this->left->equals($other->left)
            && $this->right->equals($other->right);
    }

    #[Override]
    public function getType(): Type
    {
        return Type::bool();
    }
}
