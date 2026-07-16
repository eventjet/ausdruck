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
 * The six operators still each have their own final subclass ({@see Eq}, {@see Neq}, {@see Gt}, {@see Lt}, {@see Gte},
 * {@see Lte}), but only to name the type—a subclass adds nothing but the operator it binds in its constructor. `===`
 * and `>` keep a dedicated type because {@see Expression::eq()} and {@see Expression::gt()} have returned {@see Eq} and
 * {@see Gt} since before this node existed; the rest follow the same shape so the six read alike.
 *
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
abstract class Comparison extends Expression
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
