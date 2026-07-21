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
     * Always bool, whatever the operand claims to be: this states `!`'s own contract, the way {@see Or_},
     * {@see And_} and {@see Comparison} do, rather than leaning on an invariant that {@see Expr::not()} enforces in
     * another file.
     */
    #[Override]
    public function getType(): Type
    {
        return Type::bool();
    }

    /**
     * @return Token::Not
     */
    #[Override]
    protected function token(): Token
    {
        return Token::Not;
    }
}
