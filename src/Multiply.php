<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Eventjet\Ausdruck\Parser\Span;
use Override;

use function sprintf;

/**
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class Multiply extends Expression
{
    public function __construct(public readonly Expression $multiplicand, public readonly Expression $multiplier)
    {
    }

    public function __toString(): string
    {
        return sprintf(
            '%s * %s',
            Precedence::parenthesize($this->multiplicand, Precedence::Multiplicative),
            Precedence::parenthesize($this->multiplier, Precedence::Unary),
        );
    }

    /**
     * @psalm-suppress InvalidOperand Psalm's strict binary operands mode rejects int|float on either side, because it
     *     can't see that {@see Expr::multiply()} has already required both operands to be of the *same* numeric type.
     *     The int/float mix it's guarding against can't reach us.
     */
    #[Override]
    public function evaluate(Scope $scope): int|float
    {
        return Operand::number($this->multiplicand->evaluate($scope))
            * Operand::number($this->multiplier->evaluate($scope));
    }

    #[Override]
    public function equals(Expression $other): bool
    {
        return $other instanceof self
            && $this->multiplicand->equals($other->multiplicand)
            && $this->multiplier->equals($other->multiplier);
    }

    #[Override]
    public function getType(): Type
    {
        return $this->multiplicand->getType();
    }

    #[Override]
    public function location(): Span
    {
        return $this->multiplicand->location()->to($this->multiplier->location());
    }
}
