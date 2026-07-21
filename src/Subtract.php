<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Eventjet\Ausdruck\Parser\Token;
use Override;

use function max;
use function min;

use const PHP_INT_MAX;
use const PHP_INT_MIN;

/**
 * The difference of two numbers. Total in int only up to the range, in the way {@see Arithmetic} describes.
 *
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class Subtract extends Arithmetic
{
    /**
     * The subtrahend that moves the minuend nowhere. Both bounds clamp to it, and to the same one: it is the sign
     * change, not the value, that decides which of them the subtrahend can act on.
     */
    private const int INERT = 0;

    #[Override]
    public function token(): Token
    {
        return Token::Minus;
    }

    /**
     * The largest and smallest minuend that still fits, given the subtrahend. Subtracting moves the minuend against
     * the subtrahend's sign, so this is {@see Add::applyInt()} with the clamps swapped: only a negative subtrahend can
     * carry the minuend past PHP_INT_MAX, only a positive one past PHP_INT_MIN, and clamping the other side to
     * {@see self::INERT} is again what keeps the bound computable.
     */
    #[Override]
    protected function applyInt(int $left, int $right): int|null
    {
        $highest = PHP_INT_MAX + min($right, self::INERT);
        $lowest = PHP_INT_MIN + max($right, self::INERT);
        return $left > $highest || $left < $lowest ? null : $left - $right;
    }

    #[Override]
    protected function applyFloat(float $left, float $right): float
    {
        return $left - $right;
    }
}
