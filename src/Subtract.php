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
    #[Override]
    public function token(): Token
    {
        return Token::Minus;
    }

    /**
     * The largest and smallest minuend that still fits, given the subtrahend. Subtracting moves the minuend against
     * the subtrahend's sign, so this is {@see Add::applyInt()} with the clamps swapped: only a negative subtrahend can
     * carry the minuend past PHP_INT_MAX, only a positive one past PHP_INT_MIN, and clamping to the side that acts is
     * again what keeps the bound computable. The clamps are unfalsifiable in the same one direction each, and
     * infection.json excludes the same two mutants for the reason given there.
     */
    #[Override]
    protected function applyInt(int $left, int $right): int|null
    {
        $highest = PHP_INT_MAX + min($right, 0);
        $lowest = PHP_INT_MIN + max($right, 0);
        return $left > $highest || $left < $lowest ? null : $left - $right;
    }

    #[Override]
    protected function applyFloat(float $left, float $right): float
    {
        return $left - $right;
    }
}
