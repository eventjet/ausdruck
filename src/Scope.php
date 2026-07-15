<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use LogicException;

use function array_intersect;
use function array_keys;
use function array_map;
use function get_debug_type;
use function get_object_vars;
use function implode;
use function is_bool;
use function is_int;
use function is_object;
use function is_string;
use function sprintf;

/**
 * @phpstan-type Shape array{
 *     vars?: array<string, string | int | bool | array<array-key, mixed> | null>,
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
        $predefinedFuncs = $this->parent === null ? BuiltinFunctions::implementations() : [];
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
     * @return string|int|bool|array<array-key, mixed>|null
     */
    private static function printValue(mixed $var): string|int|bool|array|null
    {
        return match (true) {
            $var === null || is_string($var) || is_int($var) || is_bool($var) => $var,
            is_object($var) => array_map(self::printValue(...), get_object_vars($var)),
            default => get_debug_type($var),
        };
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
        $vars = array_map(self::printValue(...), $this->vars);
        if ($vars !== []) {
            $shape['vars'] = $vars;
        }
        if ($this->parent !== null) {
            $shape['parent'] = $this->parent->shape();
        }
        return $shape;
    }
}
