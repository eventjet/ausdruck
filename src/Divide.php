<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Eventjet\Ausdruck\Parser\Token;
use Override;

use function intdiv;

use const PHP_INT_MIN;

/**
 * Division is total: the quotient is an option of the operand type, and operands whose quotient doesn't exist in that
 * type evaluate to none rather than throwing — the same shape the `head` builtin gives an empty list. That's every
 * zero divisor, plus PHP_INT_MIN / -1, the one int division whose result overflows int and the one input
 * {@see intdiv()} throws for. An int quotient is {@see intdiv()}, truncated toward zero.
 *
 * Which of the two divisions this is comes from the operands' declared type, never from what they evaluate to. An int
 * operand can arrive as a float when its own arithmetic overflowed, so runtime values can't tell an honest float
 * division from an int one whose operands have both left int — and reading that pair as floats would answer an
 * Option<int> with a float. Both operands are narrowed to the type they claim before the quotient is asked to exist,
 * which is also why an overflowed dividend is reported rather than excused by a divisor that happens to be zero.
 * See {@see Operand::int()}.
 *
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class Divide extends BinaryOperator
{
    #[Override]
    public function token(): Token
    {
        return Token::Slash;
    }

    #[Override]
    public function evaluate(Scope $scope): int|float|null
    {
        if ($this->left->matchesType(Type::float())) {
            $dividend = Operand::float($this->left->evaluate($scope));
            $divisor = Operand::float($this->right->evaluate($scope));
            return $divisor === 0.0 ? null : $dividend / $divisor;
        }
        $dividend = Operand::int($this->left->evaluate($scope));
        $divisor = Operand::int($this->right->evaluate($scope));
        // Besides a zero divisor, the one operand pair without an int quotient: -PHP_INT_MIN is one past PHP_INT_MAX,
        // and intdiv() throws for it.
        return $divisor === 0 || ($dividend === PHP_INT_MIN && $divisor === -1)
            ? null
            : intdiv($dividend, $divisor);
    }

    #[Override]
    public function getType(): Type
    {
        return Type::option($this->left->getType());
    }
}
