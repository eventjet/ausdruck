<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use TypeError;

use function get_debug_type;
use function sprintf;

/**
 * @template T
 * @extends Expression<T>
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final readonly class Get extends Expression
{
    /**
     * @param Type<T> $type
     */
    public function __construct(public string $name, private Type $type)
    {
    }

    public function __toString(): string
    {
        /** @psalm-suppress ImplicitToStringCast */
        return sprintf('%s:%s', $this->name, $this->type);
    }

    /**
     * @return T
     */
    public function evaluate(Scope $scope): mixed
    {
        /** @psalm-suppress MixedAssignment */
        $value = $scope->get($this->name);
        if ($value === null) {
            throw new EvaluationError(sprintf('Unknown variable "%s"', $this->name));
        }
        try {
            return $this->type->assert($value);
        } catch (TypeError $e) {
            /** @psalm-suppress ImplicitToStringCast */
            throw new EvaluationError(
                sprintf(
                    'Expected variable "%s" to be of type %s, got %s: %s',
                    $this->name,
                    $this->type,
                    get_debug_type($value),
                    $e->getMessage(),
                ),
                previous: $e,
            );
        }
    }

    public function equals(Expression $other): bool
    {
        return $other instanceof self
            && $this->name === $other->name
            && $this->type->equals($other->type);
    }

    public function getType(): Type
    {
        return $this->type;
    }
}
