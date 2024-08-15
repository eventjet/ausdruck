<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Eventjet\Ausdruck\Parser\Span;

use function get_debug_type;
use function is_object;
use function property_exists;
use function sprintf;

/**
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class FieldAccess extends Expression
{
    public function __construct(
        public readonly Expression $struct,
        public readonly string $field,
        private readonly Span $location,
    ) {
    }

    public function __toString(): string
    {
        return sprintf('%s.%s', $this->struct, $this->field);
    }

    public function location(): Span
    {
        return $this->location;
    }

    public function evaluate(Scope $scope): mixed
    {
        $struct = $this->struct->evaluate($scope);
        if (!is_object($struct)) {
            throw new EvaluationError(sprintf('Expected object, got %s', get_debug_type($struct)));
        }
        if (!property_exists($struct, $this->field)) {
            throw new EvaluationError(sprintf('Unknown field "%s"', $this->field));
        }
        /** @phpstan-ignore-next-line property.dynamicName */
        return $struct->{$this->field};
    }

    public function equals(Expression $other): bool
    {
        return $other instanceof self
            && $this->struct->equals($other->struct)
            && $this->field === $other->field;
    }

    public function getType(): Type
    {
        return $this->struct->getType()->fields[$this->field];
    }
}
