<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Eventjet\Ausdruck\Parser\Token;
use Override;

/**
 * Boolean negation. Unlike {@see Negative}, it wraps a {@see Literal} like anything else: there is no negative boolean
 * literal for {@see Expr::not()} to fold one into.
 *
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class Not extends UnaryOperator
{
    #[Override]
    public function evaluate(Scope $scope): bool
    {
        return !Operand::bool($this->expression->evaluate($scope));
    }

    /**
     * Always bool, and the only unary operator whose type doesn't come from its operand — though it might as well,
     * since {@see Expr::not()} accepts nothing but a bool.
     */
    #[Override]
    public function getType(): Type
    {
        return Type::bool();
    }

    #[Override]
    protected function token(): Token
    {
        return Token::Not;
    }
}
