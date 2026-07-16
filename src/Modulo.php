<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Eventjet\Ausdruck\Parser\Span;
use Override;

use function fmod;
use function is_int;
use function sprintf;

/**
 * Like {@see Divide}, modulo is total: the remainder is an option of the operand type, and a zero divisor makes it
 * none rather than throwing. The remainder takes the dividend's sign. A float remainder is {@see fmod()}, whose
 * NAN-on-zero quirk stays inside: the divisor is checked before it's called.
 *
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class Modulo extends Expression
{
    public function __construct(public readonly Expression $dividend, public readonly Expression $divisor)
    {
    }

    public function __toString(): string
    {
        return sprintf(
            '%s %% %s',
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
            ? Operand::int($dividend) % $divisor
            : fmod(Operand::float($dividend), $divisor);
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
