<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Eventjet\Ausdruck\Parser\Span;
use Override;

use function array_key_exists;
use function count;
use function get_object_vars;
use function is_object;

/**
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class Eq extends Expression
{
    public function __construct(public readonly Expression $left, public readonly Expression $right)
    {
    }

    private static function compareStructs(object $left, object $right): bool
    {
        $leftVars = get_object_vars($left);
        $rightVars = get_object_vars($right);
        if (count($leftVars) !== count($rightVars)) {
            return false;
        }
        /** @var mixed $value */
        foreach ($leftVars as $key => $value) {
            if (!array_key_exists($key, $rightVars)) {
                return false;
            }
            if (!self::compareValues($value, $rightVars[$key])) {
                return false;
            }
        }
        return true;
    }

    private static function compareValues(mixed $left, mixed $right): bool
    {
        if (is_object($left) && is_object($right)) {
            return self::compareStructs($left, $right);
        }
        return $left === $right;
    }

    public function __toString(): string
    {
        return Precedence::nonAssociative($this->left, '===', $this->right, Precedence::Comparison);
    }

    #[Override]
    public function evaluate(Scope $scope): bool
    {
        return self::compareValues($this->left->evaluate($scope), $this->right->evaluate($scope));
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
