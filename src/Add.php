<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Eventjet\Ausdruck\Parser\Token;
use Override;

/**
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class Add extends BinaryOperator
{
    #[Override]
    public function token(): Token
    {
        return Token::Plus;
    }

    /**
     * @psalm-suppress InvalidOperand Both operands are the same numeric type by then; Psalm's strict binary operands
     *     mode can't see that {@see Expr::assertSameNumberType()} has already rejected the int/float mix it guards
     *     against.
     */
    #[Override]
    public function evaluate(Scope $scope): int|float
    {
        return Operand::number($this->left->evaluate($scope))
            + Operand::number($this->right->evaluate($scope));
    }

    #[Override]
    public function getType(): Type
    {
        return $this->left->getType();
    }
}
