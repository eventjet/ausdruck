<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

/**
 * The `>` comparison. Its behavior lives in {@see Comparison}; this only binds {@see ComparisonOperator::GreaterThan}.
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
