<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Eventjet\Ausdruck\Parser\Token;
use Override;

/**
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class Gt extends BinaryOperator
{
    /**
     * {@see Token::CloseAngle} names `>` after its other job, closing a type parameter list like `list<int>`; the
     * lexer emits the one token for both, so this is the same `>` the parser reads here.
     */
    #[Override]
    public function token(): Token
    {
        return Token::CloseAngle;
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
