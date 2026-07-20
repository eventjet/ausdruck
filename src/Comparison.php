<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Eventjet\Ausdruck\Parser\Span;
use Override;

use function sprintf;

/**
 * A comparison of two operands by one of the six comparison operators. Everything a comparison does—printing,
 * evaluating, type-checking, comparing itself to another expression—is the same whichever operator it holds, and lives
 * here; the operator only supplies the symbol and the rule that decides it, both carried by {@see ComparisonOperator}.
 *
 * Four of the six operators are plain instances of this class, which is why it isn't abstract. `===` and `>` are the
 * exceptions: each keeps a final subclass of its own ({@see Eq}, {@see Gt}), only because {@see Expression::eq()} and
 * {@see Expression::gt()} have declared those return types since before this class existed—see {@see Eq} for why that
 * pins them.
 *
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
class Comparison extends Expression
{
    public function __construct(
        private readonly ComparisonOperator $operator,
        public readonly Expression $left,
        public readonly Expression $right,
    ) {
    }

    public function __toString(): string
    {
        return sprintf(
            '%s %s %s',
            Precedence::parenthesize($this->left, Precedence::Additive),
            $this->operator->value,
            Precedence::parenthesize($this->right, Precedence::Additive),
        );
    }

    #[Override]
    public function evaluate(Scope $scope): bool
    {
        return $this->operator->compare($this->left->evaluate($scope), $this->right->evaluate($scope));
    }

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

    #[Override]
    public function location(): Span
    {
        return $this->left->location()->to($this->right->location());
    }
}
