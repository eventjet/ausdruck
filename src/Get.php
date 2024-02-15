<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Eventjet\Ausdruck\Parser\Span;
use TypeError;

use function get_debug_type;
use function is_array;
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

    /** @var Type<T> */
    private readonly Type $type;
    private bool $typeIsImplicit;

    /**
     * @param Type<T> | array{Type<T>} $type
     */
    public function __construct(public readonly string $name, Type|array $type, Span $location)
    {
        $this->location = $location;
        $this->type = $type instanceof Type ? $type : $type[0];
        $this->typeIsImplicit = is_array($type);
    }

    public function __toString(): string
    {
        if ($this->typeIsImplicit) {
            return $this->name;
        }
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
        if ($value === null && !$this->type->isOption()) {
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
