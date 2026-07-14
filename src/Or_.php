<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Eventjet\Ausdruck\Parser\Span;
use Override;

use function sprintf;

/**
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class Or_ extends Expression
{
    public function __construct(public readonly Expression $left, public readonly Expression $right)
    {
    }

    public function __toString(): string
    {
        return sprintf('%s || %s', $this->left, $this->right);
    }

    /**
     * Short-circuits: the right operand is only evaluated if the left one is false.
     */
    #[Override]
    public function evaluate(Scope $scope): bool
    {
        return Operand::bool($this->left->evaluate($scope)) || Operand::bool($this->right->evaluate($scope));
    }

    #[Override]
    public function equals(Expression $other): bool
    {
        return $other instanceof self
            && $this->left->equals($other->left)
            && $this->right->equals($other->right);
    }

    #[Override]
    public function getType(): Type
    {
        return Type::bool();
    }

    #[Override]
    public function location(): Span
    {
        return $this->left->location()->to($this->right->location());
    }
}
