<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Eventjet\Ausdruck\Parser\Span;
use Override;

use function sprintf;

/**
 * The negation of {@see Eq}: `!==` is true exactly where `===` is false, deep struct comparison included.
 *
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class Neq extends Expression
{
    public function __construct(public readonly Expression $left, public readonly Expression $right)
    {
    }

    public function __toString(): string
    {
        return sprintf(
            '%s !== %s',
            Precedence::parenthesize($this->left, Precedence::Additive),
            Precedence::parenthesize($this->right, Precedence::Additive),
        );
    }

    #[Override]
    public function evaluate(Scope $scope): bool
    {
        return !ValueEquality::equals($this->left->evaluate($scope), $this->right->evaluate($scope));
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
