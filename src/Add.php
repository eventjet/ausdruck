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
 * The sum of two numbers. Total in int only up to the range, in the way {@see Arithmetic} describes.
 *
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class Add extends Arithmetic
{
    #[Override]
    public function token(): Token
    {
        return Token::Plus;
    }

    /**
     * The largest and smallest augend that still fits, given the addend. Only the bound the addend pushes toward moves
     * — adding a positive can't pass PHP_INT_MIN and adding a negative can't pass PHP_INT_MAX — and clamping the
     * addend to the side it acts on is what keeps the bound itself computable: taking a positive addend off
     * PHP_INT_MAX lands in [0, PHP_INT_MAX], and a negative one off PHP_INT_MIN in [PHP_INT_MIN, 0]. Subtracting the
     * addend unclamped would overflow in the very case the bound is there to catch.
     *
     * A clamp only has to be exact on the side its bound moves. On the other side the bound sits at the end of the
     * range already, where no int can be past it whatever the clamp says, so one of the two mutants on each line here
     * can't be told apart by any test — infection.json excludes those two by name.
     */
    #[Override]
    protected function applyInt(int $left, int $right): int|null
    {
        $highest = PHP_INT_MAX - max($right, 0);
        $lowest = PHP_INT_MIN - min($right, 0);
        return $left > $highest || $left < $lowest ? null : $left + $right;
    }

    #[Override]
    protected function applyFloat(float $left, float $right): float
    {
        return $left + $right;
    }
}
