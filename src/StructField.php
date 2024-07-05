<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Eventjet\Ausdruck\Parser\Span;

use function assert;
use function property_exists;
use function sprintf;

/**
 * @extends Expression<mixed>
 */
final class StructField extends Expression
{
    /**
     * @param Expression<object> $struct
     */
    public function __construct(
        public readonly Expression $struct,
        public readonly string $field,
        public readonly Span $location,
    ) {
    }

    public function __toString(): string
    {
        return sprintf('%s->%s', $this->struct, $this->field);
    }

    public function location(): Span
    {
        return $this->location;
    }

    public function evaluate(Scope $scope): mixed
    {
        $struct = $this->struct->evaluate($scope);
        assert(property_exists($struct, $this->field));
        /** @phpstan-ignore-next-line Variable property access is fine here */
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
