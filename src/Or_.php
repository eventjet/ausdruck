<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Eventjet\Ausdruck\Parser\Span;

use function get_debug_type;
use function is_bool;
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

    public function evaluate(Scope $scope): bool
    {
        $left = $this->left->evaluate($scope);
        $right = $this->right->evaluate($scope);
        if (!is_bool($left) || !is_bool($right)) {
            throw new EvaluationError(
                sprintf('Expected boolean operands, got %s and %s', get_debug_type($left), get_debug_type($right)),
            );
        }
        return $left || $right;
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

    public function location(): Span
    {
        return $this->left->location()->to($this->right->location());
    }
}
