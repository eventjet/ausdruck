<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use function array_key_exists;
use function array_keys;
use function count;
use function get_object_vars;
use function is_array;
use function is_object;

/**
 * The deep, structural equality that both `===` and `!==` ({@see ComparisonOperator}) are defined in terms of: scalars
 * compare by identity, structs field by field, recursively. It lives here rather than on the operator so that `!==` can
 * be exactly the negation of `===` without either one restating the rule.
 *
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class ValueEquality
{
    /**
     * @psalm-suppress UnusedConstructor
     */
    private function __construct()
    {
    }

    public static function equals(mixed $left, mixed $right): bool
    {
        if (is_array($left) && is_array($right)) {
            if (array_keys($left) !== array_keys($right)) {
                return false;
            }
            /** @var mixed $value */
            foreach ($left as $key => $value) {
                if (!self::equals($value, $right[$key])) {
                    return false;
                }
            }
            return true;
        }
        if ($left instanceof EnumValue || $right instanceof EnumValue) {
            if (!$left instanceof EnumValue || !$right instanceof EnumValue
                || $left->type->asEnum()?->definition !== $right->type->asEnum()?->definition
                || $left->variant !== $right->variant) {
                return false;
            }
            /** @var mixed $field */
            foreach ($left->fields as $index => $field) {
                if (!self::equals($field, $right->fields[$index])) {
                    return false;
                }
            }
            return true;
        }
        if (is_object($left) && is_object($right)) {
            return self::structsEqual($left, $right);
        }
        return $left === $right;
    }

    private static function structsEqual(object $left, object $right): bool
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
            if (!self::equals($value, $rightVars[$key])) {
                return false;
            }
        }
        return true;
    }
}
