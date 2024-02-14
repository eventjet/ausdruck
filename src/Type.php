<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Eventjet\Ausdruck\Type\AbstractType;
use Eventjet\Ausdruck\Type\FunctionType;
use InvalidArgumentException;
use LogicException;
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
 * @extends AbstractType<T>
 * @api
 */
final class Type extends AbstractType
{
    /** @var callable(mixed): T */
    private readonly mixed $assert;

    /**
     * @param callable(mixed): T $validate
     * @param list<AbstractType<mixed>> $args
     * @param self<T> | null $aliasFor
     */
    private function __construct(public readonly string $name, callable $validate, public readonly array $args = [], public readonly self|null $aliasFor = null)
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
     * @param AbstractType<U> $item
     * @return self<list<U>>
     */
    public static function listOf(AbstractType $item): self
    {
        /** @psalm-suppress ImplicitToStringCast */
        return new self('list', Assert::listOf($item), [$item]);
    }

    /**
     * @template K of array-key
     * @template V
     * @param AbstractType<K> $keys
     * @param AbstractType<V> $values
     * @return self<array<K, V>>
     */
    public static function mapOf(AbstractType $keys, AbstractType $values): self
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
     * @param AbstractType<mixed> $return
     * @param list<AbstractType<mixed>> $parameters
     */
    public static function func(AbstractType $return, array $parameters = []): FunctionType
    {
        return new FunctionType($return, $parameters);
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
     * @template U
     * @param AbstractType<U> $some
     * @return self<U | null>
     */
    public static function option(AbstractType $some): self
    {
        return new self('Option', Assert::option($some), [$some]);
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
     * @template O of AbstractType
     * @param O $type
     * @psalm-assert-if-true O $this
     */
    public function equals(AbstractType $type): bool
    {
        if (!$type instanceof self) {
            return false;
        }
        return ($this->aliasFor ?? $this)->name === ($type->aliasFor ?? $type)->name;
    }

    public function isOption(): bool
    {
        return $this->name === 'Option';
    }
}
