<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

/**
 * The `>=` comparison. Its behavior lives in {@see Comparison}; this only binds
 * {@see ComparisonOperator::GreaterThanOrEqual}.
 *
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class Gte extends Comparison
{
    public function __construct(Expression $left, Expression $right)
    {
        parent::__construct(ComparisonOperator::GreaterThanOrEqual, $left, $right);
    }
}
