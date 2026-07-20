<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Eventjet\Ausdruck\Parser\Token;
use Override;

use function intdiv;

use const PHP_INT_MIN;

/**
 * The quotient of a division, truncated toward zero for int operands. Total, in the way {@see Division} describes: the
 * quotients that don't exist are every zero divisor, plus PHP_INT_MIN / -1, the one int division whose result
 * overflows int and the one input {@see intdiv()} throws for.
 *
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class Divide extends Division
{
    #[Override]
    public function token(): Token
    {
        return Token::Slash;
    }

    /**
     * The one operand pair with a nonzero divisor and no int quotient: -PHP_INT_MIN is one past PHP_INT_MAX, and
     * {@see intdiv()} throws for it.
     */
    #[Override]
    protected function applyInt(int $dividend, int $divisor): int|null
    {
        return $dividend === PHP_INT_MIN && $divisor === -1 ? null : intdiv($dividend, $divisor);
    }

    #[Override]
    protected function applyFloat(float $dividend, float $divisor): float
    {
        return $dividend / $divisor;
    }
}
