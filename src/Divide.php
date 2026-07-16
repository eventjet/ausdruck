<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Eventjet\Ausdruck\Parser\Span;
use Override;

use function intdiv;
use function is_int;
use function sprintf;

/**
 * Division is total: the quotient is an option of the operand type, and a zero divisor makes it none rather than
 * throwing — the same shape the `head` builtin gives an empty list. An int quotient is {@see intdiv()}, truncated
 * toward zero.
 *
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class Divide extends Expression
{
    public function __construct(public readonly Expression $dividend, public readonly Expression $divisor)
    {
    }

    public function __toString(): string
    {
        return sprintf(
            '%s / %s',
            Precedence::parenthesize($this->dividend, Precedence::Multiplicative),
            Precedence::parenthesize($this->divisor, Precedence::Unary),
        );
    }

    #[Override]
    public function evaluate(Scope $scope): int|float|null
    {
        /** @psalm-suppress MixedAssignment It's narrowed in the branches below, once the divisor has decided which number type both operands share. */
        $dividend = $this->dividend->evaluate($scope);
        $divisor = Operand::number($this->divisor->evaluate($scope));
        if ($divisor === 0 || $divisor === 0.0) {
            return null;
        }
        return is_int($divisor)
            ? intdiv(Operand::int($dividend), $divisor)
            : Operand::float($dividend) / $divisor;
    }

    #[Override]
    public function equals(Expression $other): bool
    {
        return $other instanceof self
            && $this->dividend->equals($other->dividend)
            && $this->divisor->equals($other->divisor);
    }

    #[Override]
    public function getType(): Type
    {
        return Type::option($this->dividend->getType());
    }

    #[Override]
    public function location(): Span
    {
        return $this->dividend->location()->to($this->divisor->location());
    }
}
