<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use function sprintf;

/**
 * @extends Expression<bool>
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class Or_ extends Expression
{
    /**
     * @param Expression<bool> $left
     * @param Expression<bool> $right
     */
    public function __construct(public readonly Expression $left, public readonly Expression $right)
    {
    }

    public function __toString(): string
    {
        /** @psalm-suppress ImplicitToStringCast */
        return sprintf('%s || %s', $this->left, $this->right);
    }

    public function evaluate(Scope $scope): bool
    {
        return $this->left->evaluate($scope) || $this->right->evaluate($scope);
    }

    public function equals(Expression $other): bool
    {
        return $other instanceof self
            && $this->left->equals($other->left)
            && $this->right->equals($other->right);
    }

    public function getType(): Type
    {
        return Type::bool();
    }
}
