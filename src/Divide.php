<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Override;

use function intdiv;
use function is_int;

/**
 * Division is total: the quotient is an option of the operand type, and a zero divisor makes it none rather than
 * throwing — the same shape the `head` builtin gives an empty list. An int quotient is {@see intdiv()}, truncated
 * toward zero.
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
        return is_int($divisor)
            ? intdiv(Operand::int($dividend), $divisor)
            : Operand::float($dividend) / $divisor;
    }

    #[Override]
    public function getType(): Type
    {
        return Type::option($this->left->getType());
    }
}
