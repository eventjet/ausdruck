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
final class Add extends Expression
{
    public function __construct(public readonly Expression $augend, public readonly Expression $addend)
    {
    }

    public function __toString(): string
    {
        return sprintf(
            '%s + %s',
            Precedence::parenthesize($this->augend, Precedence::Additive),
            Precedence::parenthesize($this->addend, Precedence::Multiplicative),
        );
    }

    /**
     * @psalm-suppress InvalidOperand Psalm's strict binary operands mode rejects int|float on either side, because it
     *     can't see that {@see Expr::add()} has already required both operands to be of the *same* numeric type. The
     *     int/float mix it's guarding against can't reach us.
     */
    #[Override]
    public function evaluate(Scope $scope): int|float
    {
        return Operand::number($this->augend->evaluate($scope))
            + Operand::number($this->addend->evaluate($scope));
    }

    #[Override]
    public function equals(Expression $other): bool
    {
        return $other instanceof self
            && $this->augend->equals($other->augend)
            && $this->addend->equals($other->addend);
    }

    #[Override]
    public function getType(): Type
    {
        return $this->augend->getType();
    }

    #[Override]
    public function location(): Span
    {
        return $this->augend->location()->to($this->addend->location());
    }
}
