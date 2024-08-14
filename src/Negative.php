<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Eventjet\Ausdruck\Parser\Span;

use function sprintf;

final class Negative extends Expression
{
    use LocationTrait;

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

    public function getType(): Type
    {
        return $this->expression->getType();
    }
}
