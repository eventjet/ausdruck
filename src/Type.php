<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use InvalidArgumentException;
use LogicException;
use Stringable;
use TypeError;

use function array_is_list;
use function array_key_first;
use function gettype;
use function implode;
use function is_array;
use function is_object;
use function sprintf;

/**
 * @template-covariant T
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final readonly class Type implements Stringable
{
    /** @var callable(mixed): T */
    private mixed $assert;

    /**
     * @param callable(mixed): T $validate
     * @param list<self<mixed>> $args
     * @param self<T> | null $aliasFor
     */
    private function __construct(public string $name, callable $validate, public array $args = [], public self|null $aliasFor = null)
    {
        $this->assert = $validate;
    }

    /**
     * @return self<string>
     */
    public static function string(): self
    {
        return new self('string', Assert::string(...));
    }

    /**
     * @return self<int>
     */
    public static function int(): self
    {
        return new self('int', Assert::int(...));
    }

    /**
     * @return self<float>
     */
    public static function float(): self
    {
        return new self('float', Assert::float(...));
    }

    /**
     * @return self<bool>
     */
    public static function bool(): self
    {
        return new self('bool', Assert::bool(...));
    }

    /**
     * @template U
     * @param Type<U> $item
     * @return self<list<U>>
     */
    public static function listOf(self $item): self
    {
        /** @psalm-suppress ImplicitToStringCast */
        return new self('list', Assert::listOf($item), [$item]);
    }

    /**
     * @template K of array-key
     * @template V
     * @param Type<K> $keys
     * @param Type<V> $values
     * @return self<array<K, V>>
     */
    public static function mapOf(self $keys, self $values): self
    {
        /** @psalm-suppress ImplicitToStringCast */
        return new self('map', Assert::mapOf($keys, $values), [$keys, $values]);
    }

    /**
     * @template U of object
     * @param class-string<U> $class
     * @return self<U>
     */
    public static function object(string $class): self
    {
        return new self($class, Assert::class($class));
    }

    /**
     * @template U
     * @param Type<U> $type
     * @return self<U>
     */
    public static function alias(string $name, self $type): self
    {
        return new self($name, $type->assert, $type->args, $type);
    }

    /**
     * @return self<mixed>
     */
    public static function any(): self
    {
        return new self('mixed', Assert::mixed(...));
    }

    /**
     * @psalm-suppress InvalidReturnType False positive
     * @template U
     * @param U $value
     * @return self<U>
     */
    public static function fromValue(mixed $value): self
    {
        if (is_array($value)) {
            $valueType = self::valueTypeFromArray($value);
            /** @var self<U> $type We need to use an assertion here because our static analyzers aren't smart enough */
            $type = array_is_list($value)
                ? self::listOf($valueType)
                : self::mapOf(self::keyTypeFromArray($value), $valueType);
            return $type;
        }
        if (is_object($value)) {
            /**
             * @var self<U> $type We need to use an assertion here because our static analyzers aren't smart enough
             * @phpstan-ignore-next-line False positive
             */
            $type = self::object($value::class);
            return $type;
        }
        /**
         * @psalm-suppress InvalidReturnStatement False positive
         * @phpstan-ignore-next-line False positive
         */
        return match (gettype($value)) {
            'string' => self::string(),
            'integer' => self::int(),
            'boolean' => self::bool(),
            'double' => self::float(),
            default => throw new InvalidArgumentException(sprintf('Unsupported type %s', gettype($value))),
        };
    }

    /**
     * @template K of array-key
     * @param non-empty-array<K, mixed> $value
     * @return self<K>
     */
    private static function keyTypeFromArray(array $value): self
    {
        return self::fromValue(array_key_first($value));
    }

    /**
     * @template K of array-key
     * @template V
     * @param array<K, V> $value
     * @return self<V>
     * @psalm-assert non-empty-array<K, V> $value
     */
    private static function valueTypeFromArray(array $value): self
    {
        if ($value === []) {
            throw new LogicException('Can\'t infer key and value types from empty array');
        }
        return self::fromValue($value[array_key_first($value)]);
    }

    public function __toString(): string
    {
        return $this->name . ($this->args === [] ? '' : sprintf('<%s>', implode(', ', $this->args)));
    }

    /**
     * @return T
     * @throws TypeError
     */
    public function assert(mixed $value): mixed
    {
        return ($this->assert)($value);
    }

    /**
     * @template O
     * @param Type<O> $type
     * @psalm-assert-if-true self<O> $this
     */
    public function equals(self $type): bool
    {
        return ($this->aliasFor ?? $this)->name === ($type->aliasFor ?? $type)->name;
    }
}
