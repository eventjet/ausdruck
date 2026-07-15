<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Countable;

use function array_is_list;
use function array_map;
use function array_slice;
use function array_values;
use function count;
use function in_array;
use function substr;

/**
 * The single source of truth for the library's built-in functions.
 *
 * Every built-in has an implementation, exposed to evaluation ({@see Scope}) via {@see self::implementations()}. Where
 * the signature can be expressed without generics, it also has a declared {@see Type}, exposed to parsing
 * ({@see Parser\Declarations}) via {@see self::types()}; the rest (`filter`, `head`, `map`, `tail`, `unwrap`) declare
 * `null` and rely on the parser's inline-return-type escape hatch until generics land.
 *
 * Keeping the name, implementation and signature together in one table here prevents the three from drifting apart.
 *
 * @internal
 */
final class BuiltinFunctions
{
    /**
     * The implementation of every built-in, keyed by name, for use at evaluation time.
     *
     * @return array<string, callable>
     */
    public static function implementations(): array
    {
        return array_map(static fn(array $fn): callable => $fn['impl'], self::definitions());
    }

    /**
     * The declared signatures of the built-ins that can be expressed without generics, keyed by name, for use at parse
     * time. The generic ones (`filter`, `head`, `map`, `tail`, `unwrap`) are omitted.
     *
     * @return array<string, Type>
     */
    public static function types(): array
    {
        $types = [];
        foreach (self::definitions() as $name => $fn) {
            if ($fn['type'] === null) {
                continue;
            }
            $types[$name] = $fn['type'];
        }
        return $types;
    }

    /**
     * The single source of truth: name, implementation and (where expressible) declared signature, together.
     *
     * @return array<string, array{impl: callable, type: Type|null}>
     */
    private static function definitions(): array
    {
        return [
            'contains' => ['impl' => self::contains(...), 'type' => Type::func(Type::bool(), [Type::listOf(Type::any()), Type::any()])],
            'count' => ['impl' => self::count(...), 'type' => Type::func(Type::int(), [Type::listOf(Type::any())])],
            'filter' => ['impl' => self::filter(...), 'type' => null],
            'head' => ['impl' => self::head(...), 'type' => null],
            'isSome' => ['impl' => self::isSome(...), 'type' => Type::func(Type::bool(), [Type::option(Type::any())])],
            'map' => ['impl' => self::map(...), 'type' => null],
            'some' => ['impl' => self::some(...), 'type' => Type::func(Type::bool(), [Type::listOf(Type::any()), Type::func(Type::bool(), [Type::any()])])],
            'substr' => ['impl' => substr(...), 'type' => Type::func(Type::string(), [Type::string(), Type::int(), Type::int()])],
            'tail' => ['impl' => self::tail(...), 'type' => null],
            'take' => ['impl' => self::take(...), 'type' => Type::func(Type::listOf(Type::any()), [Type::listOf(Type::any()), Type::int()])],
            'unique' => ['impl' => self::unique(...), 'type' => Type::func(Type::listOf(Type::any()), [Type::listOf(Type::any())])],
            'unwrap' => ['impl' => self::identity(...), 'type' => null],
        ];
    }

    /**
     * @template K of array-key
     * @template From
     * @template To
     * @param array<K, From> $items
     * @param callable(From): To $f
     * @return array<K, To>
     */
    private static function map(array $items, callable $f): array
    {
        return array_map($f, $items);
    }

    /**
     * @template T
     * @param array<array-key, T> $haystack
     * @param callable(T): bool $predicate
     */
    private static function some(array $haystack, callable $predicate): bool
    {
        foreach ($haystack as $item) {
            if (!$predicate($item)) {
                continue;
            }
            return true;
        }
        return false;
    }

    /**
     * @template T
     * @param list<T> $items
     * @return list<T>
     */
    private static function tail(array $items): array
    {
        return array_slice($items, 1);
    }

    /**
     * @template T
     * @param array<array-key, T> $haystack
     * @param T $needle
     */
    private static function contains(array $haystack, mixed $needle): bool
    {
        foreach ($haystack as $item) {
            if ($item !== $needle) {
                continue;
            }
            return true;
        }
        return false;
    }

    /**
     * @param Countable|array<array-key, mixed> $items
     */
    private static function count(Countable|array $items): int
    {
        return count($items);
    }

    /**
     * @template K of array-key
     * @template V
     * @param array<K, V> $items
     * @param callable(V): bool $predicate
     * @return ($items is list<K> ? list<K> : array<K, V>)
     */
    private static function filter(array $items, callable $predicate): array
    {
        $out = [];
        foreach ($items as $key => $item) {
            if (!$predicate($item)) {
                continue;
            }
            $out[$key] = $item;
        }
        return array_is_list($items) ? array_values($out) : $out;
    }

    /**
     * @template T
     * @param list<T> $items
     * @return T | null
     */
    private static function head(array $items): mixed
    {
        return $items[0] ?? null;
    }

    /**
     * @template U
     * @param U | null $option
     * @return ($option is null ? false : true)
     */
    private static function isSome(mixed $option): bool
    {
        return $option !== null;
    }

    /**
     * @template T
     * @param list<T> $items
     * @return list<T>
     */
    private static function take(array $items, int $n): array
    {
        return array_slice($items, 0, $n);
    }

    /**
     * @template T
     * @param list<T> $items
     * @return list<T>
     */
    private static function unique(array $items): array
    {
        $unique = [];
        foreach ($items as $item) {
            if (in_array($item, $unique, true)) {
                continue;
            }
            $unique[] = $item;
        }
        return $unique;
    }

    /**
     * @template T
     * @param T $value
     * @return T
     */
    private static function identity(mixed $value): mixed
    {
        return $value;
    }
}
