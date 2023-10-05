<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use TypeError;

use function array_is_list;
use function array_map;
use function get_debug_type;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function sprintf;

/**
 * @phpstan-type Validator callable(mixed): (true | string)
 */
final readonly class Assert
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

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return callable(mixed): T
     */
    public static function class(string $class): callable
    {
        /**
         * @return T
         */
        $assert = static fn(mixed $value): object => $value instanceof $class
            ? $value
            : throw new TypeError(sprintf('Expected %s, got %s', $class, get_debug_type($value)));
        return $assert;
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
}
