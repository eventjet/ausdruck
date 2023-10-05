<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use function sprintf;

/**
 * @template T of int | float
 * @extends Expression<bool>
 * @internal
 */
final readonly class Gt extends Expression
{
    /**
     * @param Expression<T> $left
     * @param Expression<T> $right
     */
    public function __construct(private Expression $left, private Expression $right)
    {
    }

    public function __toString(): string
    {
        /** @psalm-suppress ImplicitToStringCast */
        return sprintf('%s > %s', $this->left, $this->right);
    }

    public function evaluate(Scope $scope): bool
    {
        return $this->left->evaluate($scope) > $this->right->evaluate($scope);
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
