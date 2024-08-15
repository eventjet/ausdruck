<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use InvalidArgumentException;
use Stringable;

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
 * @api
 */
final class Type implements Stringable
{
    /**
     * @param list<self> $args
     */
    private function __construct(public readonly string $name, public readonly array $args = [], public readonly self|null $aliasFor = null)
    {
    }

    public static function string(): self
    {
        return new self('string');
    }

    public static function int(): self
    {
        return new self('int');
    }

    public static function float(): self
    {
        return new self('float');
    }

    public static function bool(): self
    {
        return new self('bool');
    }

    public static function listOf(self $item): self
    {
        /** @psalm-suppress ImplicitToStringCast */
        return new self('list', [$item]);
    }

    public static function mapOf(self $keys, self $values): self
    {
        /** @psalm-suppress ImplicitToStringCast */
        return new self('map', [$keys, $values]);
    }

    /**
     * @param class-string $class
     */
    public static function object(string $class): self
    {
        return new self($class);
    }

    public static function alias(string $name, self $type): self
    {
        return new self($name, $type->args, $type);
    }

    public static function any(): self
    {
        return new self('any');
    }

    /**
     * @param list<Type> $parameters
     */
    public static function func(self $return, array $parameters = []): self
    {
        return new self('Func', [$return, ...$parameters]);
    }

    /**
     * @psalm-suppress InvalidReturnType False positive
     */
    public static function fromValue(mixed $value): self
    {
        if (is_array($value)) {
            [$keyType, $valueType] = self::keyAndValueTypeFromArray($value);
            return array_is_list($value) ? self::listOf($valueType) : self::mapOf($keyType, $valueType);
        }
        if (is_object($value)) {
            return self::object($value::class);
        }
        if ($value === null) {
            return self::none();
        }
        return match (gettype($value)) {
            'string' => self::string(),
            'integer' => self::int(),
            'boolean' => self::bool(),
            'double' => self::float(),
            default => throw new InvalidArgumentException(sprintf('Unsupported type %s', gettype($value))),
        };
    }

    public static function option(self $some): self
    {
        return new self('Option', [$some]);
    }

    public static function some(self $some): self
    {
        return new self('Some', [$some]);
    }

    public static function none(): self
    {
        return new self('None');
    }

    private static function never(): self
    {
        return new self('never');
    }

    /**
     * @param array<array-key, mixed> $value
     * @return array{Type, Type}
     */
    private static function keyAndValueTypeFromArray(array $value): array
    {
        if ($value === []) {
            return [self::never(), self::never()];
        }
        $firstKey = array_key_first($value);
        return [self::fromValue($firstKey), self::fromValue($value[$firstKey])];
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
     * @throws Parser\TypeError
     */
    public function assert(mixed $value): mixed
    {
        $valueType = self::fromValue($value);
        return $valueType->isSubtypeOf($this)
            ? $value
            : throw new Parser\TypeError(sprintf('Expected %s, got %s', $this, $valueType));
    }

    public function equals(self $type): bool
    {
        if (($this->aliasFor ?? $this)->name !== ($type->aliasFor ?? $type)->name) {
            return false;
        }
        if (!in_array($this->name, ['Func', 'list'], true)) {
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

    public function isSubtypeOf(self $other): bool
    {
        $self = $this->canonical();
        $other = $other->canonical();
        if ($self->isNone()) {
            return $other->isNone() || $other->isOption();
        }
        if ($other->isOption() && (!$self->isOption() && $self->name !== 'Some')) {
            return $self->isSubtypeOf($other->args[0]);
        }
        if ($self->name === 'never') {
            return true;
        }
        if ($other->name === 'any') {
            return true;
        }
        if ($self->name === 'any') {
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
        if ($this->name === 'list') {
            return $self->args[0]->isSubtypeOf($other->args[0]);
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
     */
    public function returnType(): self
    {
        return $this->args[0];
    }

    /**
     * @return list<self>
     */
    private function parameterTypes(): array
    {
        return array_slice($this->args, 1);
    }

    private function canonical(): self
    {
        return $this->aliasFor ?? $this;
    }

    private function isNone(): bool
    {
        return $this->name === 'None';
    }
}
