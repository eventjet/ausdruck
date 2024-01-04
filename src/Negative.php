<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use function sprintf;

/**
 * @template T of int | float
 * @extends Expression<T>
 */
final class Negative extends Expression
{
    /**
     * @param Expression<T> $expression
     */
    public function __construct(
        public readonly Expression $expression,
    ) {
    }

    public function __toString(): string
    {
        return sprintf('-%s', $this->expression);
    }

    /**
     * @psalm-suppress InvalidReturnType False positive
     */
    public function evaluate(Scope $scope): float|int
    {
        /** @psalm-suppress InvalidReturnStatement False positive */
        return -$this->expression->evaluate($scope);
    }

    public function equals(Expression $other): bool
    {
        return $other instanceof self
            && $this->expression->equals($other->expression);
    }

    public function getType(): Type
    {
        return $this->expression->getType();
    }
}
