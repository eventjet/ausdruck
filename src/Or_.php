<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Override;

/**
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class Or_ extends BinaryOperator
{
    #[Override]
    public function symbol(): string
    {
        return '||';
    }

    /**
     * Short-circuits: the right operand is only evaluated if the left one is false.
     */
    #[Override]
    public function evaluate(Scope $scope): bool
    {
        return Operand::bool($this->left->evaluate($scope)) || Operand::bool($this->right->evaluate($scope));
    }

    #[Override]
    public function getType(): Type
    {
        return Type::bool();
    }
}
