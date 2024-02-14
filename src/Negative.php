<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Eventjet\Ausdruck\Parser\Span;
use Eventjet\Ausdruck\Type\AbstractType;

use function sprintf;

/**
 * @template T of int | float
 * @extends Expression<T>
 */
final class Negative extends Expression
{
    use LocationTrait;

    /**
     * @param Expression<T> $expression
     */
    public function __construct(public readonly Expression $expression, Span $location)
    {
        $this->location = $location;
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

    public function getType(): AbstractType
    {
        return $this->expression->getType();
    }
}
