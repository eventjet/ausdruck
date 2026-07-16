<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Override;

use function array_key_exists;
use function count;
use function get_object_vars;
use function is_object;

/**
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class Eq extends BinaryOperator
{
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

    #[Override]
    public function symbol(): string
    {
        return '===';
    }

    #[Override]
    public function evaluate(Scope $scope): bool
    {
        return self::compareValues($this->left->evaluate($scope), $this->right->evaluate($scope));
    }

    #[Override]
    public function getType(): Type
    {
        return Type::bool();
    }
}
