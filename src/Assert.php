<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use TypeError;

use function array_is_list;
use function array_map;
use function get_debug_type;
use function is_array;
use function is_bool;
use function is_callable;
use function is_float;
use function is_int;
use function is_string;
use function sprintf;

/**
 * @phpstan-type Validator callable(mixed): (true | string)
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class Assert
{
    public static function string(mixed $value): string
    {
        if (!is_string($value)) {
            throw new TypeError(sprintf('Expected string, got %s', get_debug_type($value)));
        }
        return $value;
    }

    public static function int(mixed $value): int
    {
        if (!is_int($value)) {
            throw new TypeError(sprintf('Expected int, got %s', get_debug_type($value)));
        }
        return $value;
    }

    public static function float(mixed $value): float
    {
        if (!is_float($value)) {
            throw new TypeError(sprintf('Expected float, got %s', get_debug_type($value)));
        }
        return $value;
    }

    public static function bool(mixed $value): bool
    {
        if (!is_bool($value)) {
            throw new TypeError(sprintf('Expected bool, got %s', get_debug_type($value)));
        }
        return $value;
    }

    public static function mixed(mixed $value): mixed
    {
        return $value;
    }

    /**
     * @psalm-suppress InvalidReturnType False positive
     * @template U
     * @param Type<U> $type
     * @return callable(mixed): list<U>
     */
    public static function listOf(Type $type): callable
    {
        /**
         * @return list<U>
         */
        $assert = static function (mixed $value) use ($type): array {
            if (!is_array($value)) {
                throw new TypeError(sprintf('Expected array, got %s', get_debug_type($value)));
            }
            if (!array_is_list($value)) {
                throw new TypeError('The array is not a list');
            }
            return array_map($type->assert(...), $value);
        };
        /** @psalm-suppress InvalidReturnStatement False positive */
        return $assert;
    }

    /**
     * @template K of array-key
     * @template V
     * @param Type<K> $keys
     * @param Type<V> $values
     * @return callable(mixed): array<K, V>
     */
    public static function mapOf(Type $keys, Type $values): callable
    {
        /**
         * @return array<K, V>
         */
        $assert = static function (mixed $value) use ($keys, $values): array {
            if (!is_array($value)) {
                throw new TypeError(sprintf('Expected array, got %s', get_debug_type($value)));
            }
            $out = [];
            /**
             * @var mixed $key
             * @var mixed $item
             */
            foreach ($value as $key => $item) {
                $out[$keys->assert($key)] = $values->assert($item);
            }
            return $out;
        };
        return $assert;
    }

    /**
     * @template U
     * @param Type<U> $some
     * @return callable(mixed): (U | null)
     */
    public static function option(Type $some): callable
    {
        /**
         * @return U | null
         */
        $assert = static fn(mixed $value): mixed => $value === null ? null : $some->assert($value);
        return $assert;
    }

    /**
     * @template U
     * @param Type<U> $returnType
     * @return callable(mixed): (callable(mixed...): U)
     */
    public static function func(Type $returnType): callable
    {
        /**
         * @return callable(): U
         */
        $assert = static function (mixed $value) use ($returnType): callable {
            if (!is_callable($value)) {
                throw new Parser\TypeError('Expected callable, got ' . get_debug_type($value));
            }
            /**
             * @param mixed ...$params
             * @return U
             */
            $fn = static function (mixed ...$params) use ($returnType, $value): mixed {
                return $returnType->assert($value(...$params));
            };
            return $fn;
        };
        return $assert;
    }
}
