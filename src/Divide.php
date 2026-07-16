<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Override;

use function intdiv;
use function is_int;

use const PHP_INT_MIN;

/**
 * Division is total: the quotient is an option of the operand type, and operands whose quotient doesn't exist in that
 * type evaluate to none rather than throwing — the same shape the `head` builtin gives an empty list. That's every
 * zero divisor, plus PHP_INT_MIN / -1, the one int division whose result overflows int and the one input
 * {@see intdiv()} throws for. An int quotient is {@see intdiv()}, truncated toward zero.
 *
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class Divide extends BinaryOperator
{
    #[Override]
    public function symbol(): string
    {
        return '/';
    }

    #[Override]
    public function evaluate(Scope $scope): int|float|null
    {
        /** @psalm-suppress MixedAssignment It's narrowed in the branches below, once the divisor has decided which number type both operands share. */
        $dividend = $this->left->evaluate($scope);
        $divisor = Operand::number($this->right->evaluate($scope));
        if ($divisor === 0 || $divisor === 0.0) {
            return null;
        }
        if (!is_int($divisor)) {
            return Operand::float($dividend) / $divisor;
        }
        $dividend = Operand::int($dividend);
        // The one nonzero divisor without an int quotient: -PHP_INT_MIN is one past PHP_INT_MAX, and intdiv() throws.
        return $dividend === PHP_INT_MIN && $divisor === -1
            ? null
            : intdiv($dividend, $divisor);
    }

    #[Override]
    public function getType(): Type
    {
        return Type::option($this->left->getType());
    }
}
