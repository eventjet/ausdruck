<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Eventjet\Ausdruck\Parser\Token;
use Override;

use function intdiv;

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
     * Dividing by -1 is negating, so the one nonzero divisor with a quotient that may not exist is handled by
     * {@see IntArithmetic::negate()} — which is also the one input {@see intdiv()} throws for rather than answers.
     */
    #[Override]
    protected function applyInt(int $dividend, int $divisor): int|null
    {
        return $divisor === -1 ? IntArithmetic::negate($dividend) : intdiv($dividend, $divisor);
    }

    #[Override]
    protected function applyFloat(float $dividend, float $divisor): float
    {
        return $dividend / $divisor;
    }
}
