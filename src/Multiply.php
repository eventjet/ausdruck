<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Eventjet\Ausdruck\Parser\Token;
use Override;

use function intdiv;
use function max;
use function min;

use const PHP_INT_MAX;
use const PHP_INT_MIN;

/**
 * The product of two numbers. Total in int only up to the range, in the way {@see Arithmetic} describes.
 *
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class Multiply extends Arithmetic
{
    #[Override]
    public function token(): Token
    {
        return Token::Asterisk;
    }

    /**
     * The two multiplicands that land exactly on a bound, and the range they enclose. {@see intdiv()} truncates toward
     * zero, which is toward the inside of whichever bound the product approaches, so each quotient is exactly the last
     * multiplicand that still fits — the range neither rejects a representable product nor admits an overflowing one.
     * A negative multiplier reverses which quotient is the upper and which the lower, so they're ordered by value
     * rather than by which limit they came from.
     *
     * The two multipliers that can't be asked are handled first. Zero would make {@see intdiv()} throw, and multiplying
     * by -1 is negating, which is where the one missing product is: see {@see IntArithmetic::negate()}.
     */
    #[Override]
    protected function applyInt(int $left, int $right): int|null
    {
        if ($right === 0) {
            return 0;
        }
        if ($right === -1) {
            return IntArithmetic::negate($left);
        }
        $onMax = intdiv(PHP_INT_MAX, $right);
        $onMin = intdiv(PHP_INT_MIN, $right);
        return $left > max($onMax, $onMin) || $left < min($onMax, $onMin) ? null : $left * $right;
    }

    #[Override]
    protected function applyFloat(float $left, float $right): float
    {
        return $left * $right;
    }
}
