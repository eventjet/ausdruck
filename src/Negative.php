<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Eventjet\Ausdruck\Parser\Span;
use Override;

use function sprintf;

use const PHP_INT_MIN;

/**
 * Never wraps a {@see Literal}: {@see Expr::negative()} folds a negated number literal into a negative one.
 */
final class Negative extends Expression
{
    use LocationTrait;

    public function __construct(public readonly Expression $expression, Span $location)
    {
        $this->location = $location;
    }

    public function __toString(): string
    {
        return sprintf('-%s', Precedence::parenthesize($this->expression, Precedence::Unary));
    }

    /**
     * Negation is arithmetic too, and follows the rule {@see Arithmetic} sets: the operand is narrowed to the type it
     * claims, and an int result that doesn't exist is reported rather than widened. -PHP_INT_MIN is the one such
     * result — it is one past PHP_INT_MAX, the same overflow {@see Divide} has no quotient for.
     */
    #[Override]
    public function evaluate(Scope $scope): float|int
    {
        if ($this->expression->matchesType(Type::float())) {
            return -Operand::float($this->expression->evaluate($scope));
        }
        $value = Operand::int($this->expression->evaluate($scope));
        return $value === PHP_INT_MIN
            ? throw new EvaluationError(sprintf('%s leaves the int range', $this))
            : -$value;
    }

    #[Override]
    public function equals(Expression $other): bool
    {
        return $other instanceof self
            && $this->expression->equals($other->expression);
    }

    #[Override]
    public function getType(): Type
    {
        return $this->expression->getType();
    }
}
