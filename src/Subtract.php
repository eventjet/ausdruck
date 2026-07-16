<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Override;

/**
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class Subtract extends BinaryOperator
{
    #[Override]
    public function symbol(): string
    {
        return '-';
    }

    /**
     * @psalm-suppress InvalidOperand Psalm's strict binary operands mode rejects int|float on either side, because it
     *     can't see that {@see Expr::subtract()} has already required both operands to be of the *same* numeric type.
     *     The int/float mix it's guarding against can't reach us.
     */
    #[Override]
    public function evaluate(Scope $scope): int|float
    {
        return Operand::number($this->left->evaluate($scope))
            - Operand::number($this->right->evaluate($scope));
    }

    #[Override]
    public function getType(): Type
    {
        return $this->left->getType();
    }
}
