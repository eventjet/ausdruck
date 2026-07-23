<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Eventjet\Ausdruck\Parser\Token;
use Override;

/**
 * A comparison of two operands by one of the six comparison operators. Everything a comparison does—printing,
 * evaluating, type-checking, comparing itself to another expression—is the same whichever operator it holds, and lives
 * here or in {@see BinaryOperator}; the operator only supplies the symbol and the rule that decides it, both carried by
 * {@see ComparisonOperator}. All six operators are plain instances of this class, which is why it isn't abstract and
 * why it is final: there is nothing an operator-specific subclass would carry that {@see $operator} doesn't.
 *
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class Comparison extends BinaryOperator
{
    public function __construct(
        private readonly ComparisonOperator $operator,
        Expression $left,
        Expression $right,
    ) {
        parent::__construct($left, $right);
    }

    #[Override]
    public function token(): Token
    {
        return $this->operator->token();
    }

    #[Override]
    public function evaluate(Scope $scope): bool
    {
        return $this->operator->compare($this->left->evaluate($scope), $this->right->evaluate($scope));
    }

    /**
     * Which operator is held is part of a comparison's identity, and it is the one piece of that identity
     * {@see BinaryOperator::equals()} can't see: all six operators are the same class, so `a < b` and `a >= b` would
     * otherwise compare equal.
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
