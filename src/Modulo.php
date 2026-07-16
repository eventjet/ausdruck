<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Override;

use function fmod;
use function is_int;

/**
 * Like {@see Divide}, modulo is total: the remainder is an option of the operand type, and a zero divisor makes it
 * none rather than throwing. The remainder takes the dividend's sign. A float remainder is {@see fmod()}, whose
 * NAN-on-zero quirk stays inside: the divisor is checked before it's called.
 *
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class Modulo extends BinaryOperator
{
    #[Override]
    public function symbol(): string
    {
        return '%';
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
            ? Operand::int($dividend) % $divisor
            : fmod(Operand::float($dividend), $divisor);
    }

    #[Override]
    public function getType(): Type
    {
        return Type::option($this->left->getType());
    }
}
