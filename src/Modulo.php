<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Eventjet\Ausdruck\Parser\Token;
use Override;

use function fmod;

/**
 * The remainder of a division, taking the dividend's sign. Total, in the way {@see Division} describes, and a zero
 * divisor is the only operand pair without a remainder — the division whose quotient overflows int still has one,
 * since PHP_INT_MIN % -1 is 0. A float remainder is {@see fmod()}, whose NAN-on-zero quirk stays inside: the divisor
 * is checked before it's called.
 *
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class Modulo extends Division
{
    #[Override]
    public function token(): Token
    {
        return Token::Percent;
    }

    #[Override]
    protected function applyInt(int $dividend, int $divisor): int
    {
        return $dividend % $divisor;
    }

    #[Override]
    protected function applyFloat(float $dividend, float $divisor): float
    {
        return fmod($dividend, $divisor);
    }
}
