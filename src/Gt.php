<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Override;

/**
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class Gt extends BinaryOperator
{
    #[Override]
    public function symbol(): string
    {
        return '>';
    }

    #[Override]
    public function evaluate(Scope $scope): bool
    {
        return Operand::number($this->left->evaluate($scope)) > Operand::number($this->right->evaluate($scope));
    }

    #[Override]
    public function getType(): Type
    {
        return Type::bool();
    }
}
