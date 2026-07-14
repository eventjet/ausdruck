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
final class Subtract extends Expression
{
    public function __construct(public readonly Expression $minuend, public readonly Expression $subtrahend)
    {
    }

    public function __toString(): string
    {
        return sprintf('%s - %s', $this->minuend, $this->subtrahend);
    }

    /**
     * @psalm-suppress InvalidOperand Psalm's strict binary operands mode rejects int|float on either side, because it
     *     can't see that {@see Expr::subtract()} has already required both operands to be of the *same* numeric type.
     *     The int/float mix it's guarding against can't reach us.
     */
    #[Override]
    public function evaluate(Scope $scope): int|float
    {
        return Operand::number($this->minuend->evaluate($scope))
            - Operand::number($this->subtrahend->evaluate($scope));
    }

    #[Override]
    public function equals(Expression $other): bool
    {
        return $other instanceof self
            && $this->minuend->equals($other->minuend)
            && $this->subtrahend->equals($other->subtrahend);
    }

    #[Override]
    public function getType(): Type
    {
        return $this->minuend->getType();
    }

    #[Override]
    public function location(): Span
    {
        return $this->minuend->location()->to($this->subtrahend->location());
    }
}
