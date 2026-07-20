<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

/**
 * The `>` comparison: a {@see Comparison} holding {@see ComparisonOperator::GreaterThan}. It survives for the same
 * reason {@see Eq} does—{@see Expression::gt()} has declared this return type since before Comparison existed—and
 * folds into Comparison with it in the next breaking release.
 *
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class Gt extends Comparison
{
    public function __construct(Expression $left, Expression $right)
    {
        parent::__construct(ComparisonOperator::GreaterThan, $left, $right);
    }
}
