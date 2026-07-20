<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

/**
 * The `===` comparison: a {@see Comparison} holding {@see ComparisonOperator::Equals}, and nothing else. The class
 * survives because {@see Expression::eq()} has declared it as its return type since before Comparison existed, and
 * Expression is public API: consumers may type against what eq() returns, so widening the declaration to Comparison
 * is a breaking change—the backward-compatibility check in CI flags exactly that. Folds into Comparison in the next
 * breaking release, together with {@see Gt}.
 *
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class Eq extends Comparison
{
    public function __construct(Expression $left, Expression $right)
    {
        parent::__construct(ComparisonOperator::Equals, $left, $right);
    }
}
