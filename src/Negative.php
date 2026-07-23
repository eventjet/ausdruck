<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Eventjet\Ausdruck\Parser\Token;
use Override;

/**
 * Never wraps a {@see Literal}: {@see Expr::negative()} folds a negated number literal into a negative one.
 *
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class Negative extends UnaryOperator
{
    /**
     * Negation is arithmetic too, and follows the rule {@see Arithmetic} sets: the operand is narrowed to the type it
     * claims, and an int result that doesn't exist is reported rather than widened. Which int result that is, and why,
     * is {@see IntArithmetic::negate()}'s to say.
     */
    #[Override]
    public function evaluate(Scope $scope): float|int
    {
        if ($this->expression->matchesType(Type::float())) {
            return -Operand::float($this->expression->evaluate($scope));
        }
        $value = Operand::int($this->expression->evaluate($scope));
        return IntArithmetic::negate($value) ?? throw EvaluationError::outsideIntRange($this);
    }

    #[Override]
    public function getType(): Type
    {
        return $this->expression->getType();
    }

    /**
     * @return Token::Minus
     */
    #[Override]
    protected function token(): Token
    {
        return Token::Minus;
    }
}
