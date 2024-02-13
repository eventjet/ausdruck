<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Countable;
use LogicException;

use function array_intersect;
use function array_is_list;
use function array_keys;
use function array_map;
use function array_slice;
use function array_values;
use function count;
use function get_debug_type;
use function implode;
use function is_bool;
use function is_int;
use function is_string;
use function sprintf;

/**
 * @phpstan-type Shape array{
 *     vars?: array<string, string | int | bool | null>,
 *     parent?: mixed,
 * }
 * @api
 */
final class Scope
{
    /** @var array<string, callable> */
    private readonly array $funcs;

    /**
     * @param array<string, mixed> $vars
     * @param array<string, callable> $funcs
     */
    public function __construct(private readonly array $vars = [], array $funcs = [], private readonly Scope|null $parent = null)
    {
        $predefinedFuncs = $this->parent === null ? [
            'contains' => self::contains(...),
            'count' => self::count(...),
            'filter' => self::filter(...),
            'isSome' => self::isSome(...),
            'map' => self::map(...),
            'some' => self::some(...),
            'substr' => substr(...),
            'take' => self::take(...),
        ] : [];
        $shadowed = array_intersect(array_keys($predefinedFuncs), array_keys($funcs));
        if ($shadowed !== []) {
            throw new LogicException(sprintf('Can\'t shadow predefined functions: %s', implode(', ', $shadowed)));
        }
        $this->funcs = [...$predefinedFuncs, ...$funcs];
        if ($this->parent === null) {
            return;
        }
        foreach (array_keys($this->vars) as $name) {
            if ($this->parent->get($name) === null) {
                continue;
            }
            throw new LogicException(sprintf('Can\'t shadow variable "%s" in ancestor scope', $name));
        }
        foreach (array_keys($this->funcs) as $name) {
            if ($this->parent->func($name) === null) {
                continue;
            }
            throw new LogicException(sprintf('Can\'t shadow function "%s" in ancestor scope', $name));
        }
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
     * @internal
     */
    public function debug(): string
    {
        $shape = $this->shape();
        if ($shape === []) {
            return '{}';
        }
        return (string)Expr::literal($shape);
    }

    public function get(string $name): mixed
    {
        /** @infection-ignore-all It can only be here OR in an ancestor. Flipping the first two makes no difference. */
        return $this->vars[$name] ?? $this->parent?->get($name) ?? null;
    }

    /**
     * @param array<string, mixed> $vars
     * @param array<string, callable> $funcs
     */
    public function sub(array $vars = [], array $funcs = []): self
    {
        return new self($vars, $funcs, $this);
    }

    public function func(string $name): callable|null
    {
        /** @infection-ignore-all It can only be here OR in an ancestor. Flipping the first two makes no difference. */
        return $this->funcs[$name] ?? $this->parent?->func($name) ?? null;
    }

    /**
     * @return Shape
     */
    private function shape(): array
    {
        $shape = [];
        $vars = array_map(static function (mixed $var): string|int|bool|null {
            return match (true) {
                $var === null || is_string($var) || is_int($var) || is_bool($var) => $var,
                default => get_debug_type($var),
            };
        }, $this->vars);
        if ($vars !== []) {
            $shape['vars'] = $vars;
        }
        if ($this->parent !== null) {
            $shape['parent'] = $this->parent->shape();
        }
        return $shape;
    }
}
