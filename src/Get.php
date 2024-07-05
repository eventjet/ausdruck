<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Eventjet\Ausdruck\Parser\Span;
use Eventjet\Ausdruck\Parser\TypeHint;

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

    /** @var TypeHint<T> */
    private readonly TypeHint $typeHint;

    /**
     * @param Type<T> | TypeHint<T> $type
     */
    public function __construct(public readonly string $name, Type|TypeHint $type, Span $location)
    {
        $this->location = $location;
        if ($type instanceof Type) {
            $type = new TypeHint($type, true);
        }
        $this->typeHint = $type;
    }

    public function __toString(): string
    {
        return sprintf('%s%s', $this->name, $this->typeHint);
    }

    /**
     * @return T
     */
    public function evaluate(Scope $scope): mixed
    {
        /** @psalm-suppress MixedAssignment */
        $value = $scope->get($this->name);
        if ($value === null && !$this->typeHint->type->isOption()) {
            throw new EvaluationError(sprintf('Unknown variable "%s"', $this->name));
        }
        try {
            return $this->typeHint->type->assert($value);
        } catch (Parser\TypeError $e) {
            /** @psalm-suppress ImplicitToStringCast */
            throw new EvaluationError(
                sprintf(
                    'Expected variable "%s" to be of type %s, got %s: %s',
                    $this->name,
                    $this->typeHint->type,
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
            && $this->typeHint->type->equals($other->typeHint->type);
    }

    public function getType(): Type
    {
        return $this->typeHint->type;
    }
}
