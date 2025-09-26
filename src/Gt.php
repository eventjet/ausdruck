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
final class Gt extends Expression
{
    public function __construct(private readonly Expression $left, private readonly Expression $right)
    {
    }

    public function __toString(): string
    {
        return sprintf('%s > %s', $this->left, $this->right);
    }

    #[Override]
    public function evaluate(Scope $scope): bool
    {
        return $this->left->evaluate($scope) > $this->right->evaluate($scope);
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
