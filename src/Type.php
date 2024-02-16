<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use InvalidArgumentException;
use LogicException;
use Stringable;
use TypeError;

use function array_is_list;
use function array_key_first;
use function array_shift;
use function array_slice;
use function gettype;
use function implode;
use function in_array;
use function is_array;
use function is_object;
use function sprintf;

/**
 * @template-covariant T
 * @api
 */
final class Type implements Stringable
{
    /** @var callable(mixed): T */
    private readonly mixed $assert;

    /**
     * @param callable(mixed): T $validate
     * @param list<Type<mixed>> $args
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
        return new self('any', Assert::mixed(...));
    }

    /**
     * @template U
     * @param Type<U> $return
     * @param list<Type<mixed>> $parameters
     * @return self<callable(mixed...): U>
     */
    public static function func(self $return, array $parameters = []): self
    {
        return new self('Func', Assert::func($return), [$return, ...$parameters]);
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
     * @param Type<U> $some
     * @return self<U | null>
     */
    public static function option(self $some): self
    {
        return new self('Option', Assert::option($some), [$some]);
    }

    /**
     * @template U
     * @param Type<U> $some
     * @return self<U>
     */
    public static function some(self $some): self
    {
        return new self('Some', $some->assert, [$some]);
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
        if ($this->name === 'Func') {
            $args = $this->args;
            $returnType = array_shift($args);
            return sprintf('func(%s): %s', implode(', ', $args), $returnType);
        }
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
     * @template O of Type
     * @param O $type
     * @psalm-assert-if-true O $this
     */
    public function equals(self $type): bool
    {
        if (($this->aliasFor ?? $this)->name !== ($type->aliasFor ?? $type)->name) {
            return false;
        }
        if ($this->name !== 'Func') {
            return true;
        }
        foreach ($this->args as $i => $arg) {
            /**
             * @psalm-suppress RedundantCondition I think it's complaining about Type<mixed> being equal to Type<mixed>,
             *     but I don't know how to fix it.
             */
            if ($arg->equals($type->args[$i])) {
                continue;
            }
            return false;
        }
        return true;
    }

    public function isOption(): bool
    {
        return $this->name === 'Option';
    }

    /**
     * @param Type<mixed> $other
     */
    public function isSubtypeOf(self $other): bool
    {
        $self = $this->canonical();
        $other = $other->canonical();
        if ($other->name === 'mixed') {
            return true;
        }
        if ($self->name === 'mixed') {
            return false;
        }
        if ($self->name === 'Option') {
            return $other->name === 'Option' && $self->args[0]->isSubtypeOf($other->args[0]);
        }
        if ($self->name === 'Some') {
            return in_array($other->name, ['Option', 'Some'], true) && $self->args[0]->isSubtypeOf($other->args[0]);
        }
        if ($self->name !== $other->name) {
            return false;
        }
        if ($self->name === 'Func') {
            if (!$self->returnType()->isSubtypeOf($other->returnType())) {
                return false;
            }
            $params = $self->parameterTypes();
            $otherParams = $other->parameterTypes();
            foreach ($params as $i => $param) {
                $otherParam = $otherParams[$i] ?? null;
                if ($otherParam === null) {
                    return false;
                }
                if (!$otherParam->isSubtypeOf($param)) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Returns the return type of a function type.
     *
     * This should only be called on function types. The behavior is undefined for other types.
     *
     * @return self<mixed>
     */
    public function returnType(): self
    {
        return $this->args[0];
    }

    /**
     * @return list<self<mixed>>
     */
    private function parameterTypes(): array
    {
        return array_slice($this->args, 1);
    }

    /**
     * @return self<T>
     */
    private function canonical(): self
    {
        return $this->aliasFor ?? $this;
    }
}
