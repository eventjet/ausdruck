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

    #[Override]
    final public function token(): Token
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
