<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Eventjet\Ausdruck\Parser\Token;
use Override;

use function fmod;

/**
 * Like {@see Divide}, modulo is total: the remainder is an option of the operand type, and a zero divisor makes it
 * none rather than throwing. A zero divisor is also the only operand pair without a remainder — the division whose
 * quotient overflows int still has one, since PHP_INT_MIN % -1 is 0. The remainder takes the dividend's sign. A float
 * remainder is {@see fmod()}, whose NAN-on-zero quirk stays inside: the divisor is checked before it's called.
 *
 * Which of the two remainders this is comes from the operands' declared type, and both are narrowed to the type they
 * claim before the remainder is asked to exist, for the reasons spelled out in {@see Divide}.
 *
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class Modulo extends BinaryOperator
{
    #[Override]
    public function token(): Token
    {
        return Token::Percent;
    }

    #[Override]
    public function evaluate(Scope $scope): int|float|null
    {
        if ($this->left->matchesType(Type::float())) {
            $dividend = Operand::float($this->left->evaluate($scope));
            $divisor = Operand::float($this->right->evaluate($scope));
            return $divisor === 0.0 ? null : fmod($dividend, $divisor);
        }
        $dividend = Operand::int($this->left->evaluate($scope));
        $divisor = Operand::int($this->right->evaluate($scope));
        return $divisor === 0 ? null : $dividend % $divisor;
    }

    #[Override]
    public function getType(): Type
    {
        return Type::option($this->left->getType());
    }
}
