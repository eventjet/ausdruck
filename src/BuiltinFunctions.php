<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Countable;

use function array_map;
use function array_slice;
use function count;
use function in_array;
use function substr;

/**
 * The single source of truth for the library's built-in functions.
 *
 * Every built-in has an implementation, exposed to evaluation ({@see Scope}) via {@see self::implementations()}, and a
 * declared {@see Type}, exposed to parsing ({@see Parser\Declarations}) via {@see self::types()}. Most of them are
 * generic: they say what they do to the elements of the list they're given without saying what those elements are, so
 * their signatures are written with {@see Type::var()} and are resolved per call site by {@see Type::instantiate()}.
 * That's what lets `count` accept any list while `contains` still insists that the needle is of the list's own element
 * type, and what makes `map` return a list of whatever its lambda returns.
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
     * The declared signature of every built-in, keyed by name, for use at parse time.
     *
     * @return array<string, Type>
     */
    public static function types(): array
    {
        return array_map(static fn(array $fn): Type => $fn['type'], self::definitions());
    }

    /**
     * The single source of truth: name, implementation and declared signature, together.
     *
     * The list functions are written against one element type, `T`, so that the element type a call is given decides
     * what else that call accepts and what it returns: `contains` takes a needle of the list's own element type,
     * `head` answers an `Option` of it, and `map` answers a list of whatever its lambda returns, `U`. See
     * {@see Type::instantiate()} for how a call site decides them.
     *
     * @return array<string, array{impl: callable, type: Type}>
     */
    private static function definitions(): array
    {
        $item = Type::var('T');
        $items = Type::listOf($item);
        $predicate = Type::func(Type::bool(), [$item]);
        $mapped = Type::var('U');
        return [
            'contains' => ['impl' => self::contains(...), 'type' => Type::func(Type::bool(), [$items, $item])],
            // Not generic: count and isSome answer the same thing whatever the list or Option holds, so neither reads
            // an element type and declaring one would only be a variable no parameter ever decides.
            'count' => ['impl' => self::count(...), 'type' => Type::func(Type::int(), [Type::listOf(Type::any())])],
            'filter' => ['impl' => self::filter(...), 'type' => Type::func($items, [$items, $predicate])],
            'head' => ['impl' => self::head(...), 'type' => Type::func(Type::option($item), [$items])],
            'isSome' => ['impl' => self::isSome(...), 'type' => Type::func(Type::bool(), [Type::option(Type::any())])],
            'map' => ['impl' => self::map(...), 'type' => Type::func(Type::listOf($mapped), [$items, Type::func($mapped, [$item])])],
            'some' => ['impl' => self::some(...), 'type' => Type::func(Type::bool(), [$items, $predicate])],
            'substr' => ['impl' => substr(...), 'type' => Type::func(Type::string(), [Type::string(), Type::int(), Type::int()])],
            'tail' => ['impl' => self::tail(...), 'type' => Type::func($items, [$items])],
            'take' => ['impl' => self::take(...), 'type' => Type::func($items, [$items, Type::int()])],
            'unique' => ['impl' => self::unique(...), 'type' => Type::func($items, [$items])],
            'unwrap' => ['impl' => self::identity(...), 'type' => Type::func($item, [Type::option($item)])],
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
     * @template T
     * @param list<T> $items
     * @param callable(T): bool $predicate
     * @return list<T>
     */
    private static function filter(array $items, callable $predicate): array
    {
        $out = [];
        foreach ($items as $item) {
            if (!$predicate($item)) {
                continue;
            }
            $out[] = $item;
        }
        return $out;
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
