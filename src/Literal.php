<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use function array_is_list;
use function array_map;
use function implode;
use function is_bool;
use function is_float;
use function is_int;
use function is_null;
use function is_string;
use function sprintf;

/**
 * @template T of string | int | float | bool | null | array<array-key, mixed>
 * @extends Expression<T>
 * @internal
 */
final readonly class Literal extends Expression
{
    /**
     * @param T $value
     */
    public function __construct(private mixed $value)
    {
    }

    /**
     * @param string | int | float | bool | null | array<array-key, mixed> $value
     */
    private static function dumpValue(mixed $value): string
    {
        if (is_string($value)) {
            return sprintf('"%s"', $value);
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_null($value)) {
            return 'null';
        }
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }
        if (array_is_list($value)) {
            return sprintf('[%s]', implode(', ', array_map(self::dumpValue(...), $value)));
        }
        $pairs = [];
        /** @var string | int | bool | null | array<array-key, mixed> $item */
        foreach ($value as $key => $item) {
            $pairs[] = sprintf('%s: %s', self::dumpValue($key), self::dumpValue($item));
        }
        return sprintf('{%s}', implode(', ', $pairs));
    }

    public function __toString(): string
    {
        return self::dumpValue($this->value);
    }

    /**
     * @return T
     */
    public function evaluate(Scope $scope): mixed
    {
        return $this->value;
    }

    public function equals(Expression $other): bool
    {
        return $other instanceof self
            && $this->value === $other->value;
    }

    /**
     * @return Type<T>
     */
    public function getType(): Type
    {
        return Type::fromValue($this->value);
    }
}
