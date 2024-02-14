<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Eventjet\Ausdruck\Parser\Span;
use Eventjet\Ausdruck\Type\AbstractType;
use TypeError;

use function get_debug_type;
use function sprintf;

/**
 * @template T
 * @extends Expression<T>
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class Get extends Expression
{
    use LocationTrait;

    /**
     * @param AbstractType<T> $type
     */
    public function __construct(public readonly string $name, private readonly AbstractType $type, Span $location)
    {
        $this->location = $location;
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
        if ($value === null && (!$this->type instanceof Type || !$this->type->isOption())) {
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

    public function getType(): AbstractType
    {
        return $this->type;
    }
}
