<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Eventjet\Ausdruck\Parser\Span;
use Override;

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

    #[Override]
    public function evaluate(Scope $scope): float|int
    {
        return -Operand::number($this->expression->evaluate($scope));
    }

    #[Override]
    public function equals(Expression $other): bool
    {
        return $other instanceof self
            && $this->expression->equals($other->expression);
    }

    #[Override]
    public function getType(): Type
    {
        return $this->expression->getType();
    }
}
