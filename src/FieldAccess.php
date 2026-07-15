<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Eventjet\Ausdruck\Parser\Span;
use Override;

use function property_exists;
use function sprintf;

/**
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class FieldAccess extends Expression
{
    /**
     * @param Type $type The type {@see $field} is declared as on {@see $struct}. {@see Expr::fieldAccess()} resolves it,
     *     which is also where we know the field exists at all.
     */
    public function __construct(
        public readonly Expression $struct,
        public readonly string $field,
        private readonly Type $type,
        private readonly Span $location,
    ) {
    }

    public function __toString(): string
    {
        return sprintf('%s.%s', Precedence::parenthesize($this->struct, Precedence::PRIMARY), $this->field);
    }

    #[Override]
    public function location(): Span
    {
        return $this->location;
    }

    #[Override]
    public function evaluate(Scope $scope): mixed
    {
        $struct = Operand::struct($this->struct->evaluate($scope));
        if (!property_exists($struct, $this->field)) {
            /**
             * Unreachable; see {@see Operand}. Kept because reading a missing property would quietly yield null rather
             * than fail, which is the worst possible way for a bug in this library to surface.
             *
             * @infection-ignore-all
             */
            throw new EvaluationError(sprintf('Unknown field "%s"', $this->field));
        }
        /** @phpstan-ignore-next-line property.dynamicName */
        return $struct->{$this->field};
    }

    #[Override]
    public function equals(Expression $other): bool
    {
        return $other instanceof self
            && $this->struct->equals($other->struct)
            && $this->field === $other->field;
    }

    #[Override]
    public function getType(): Type
    {
        return $this->type;
    }
}
