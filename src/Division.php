<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Override;

/**
 * The quotient and the remainder of a division: the two operators whose result may not exist in the operand type, and
 * which are therefore total in the same way. Both give an option of that type, and both answer none rather than
 * throwing when the result doesn't exist — the same shape the `head` builtin gives an empty list. A zero divisor is
 * the case they share; {@see Divide} has one more.
 *
 * Which of the two arithmetics either one is comes from the operands' declared type, never from what they evaluate to:
 * the declared type is what {@see self::getType()} has already committed the result to, so deciding it any other way
 * could answer an Option<int> with a float. Both operands are narrowed to the type they claim before the result is
 * asked to exist, the same way {@see Arithmetic} — the other half of the arithmetic, whose result is of the operand
 * type rather than an option of it — narrows its own.
 *
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
abstract class Division extends BinaryOperator
{
    #[Override]
    final public function evaluate(Scope $scope): int|float|null
    {
        if ($this->left->matchesType(Type::float())) {
            $dividend = Operand::float($this->left->evaluate($scope));
            $divisor = Operand::float($this->right->evaluate($scope));
            return $divisor === 0.0 ? null : $this->applyFloat($dividend, $divisor);
        }
        $dividend = Operand::int($this->left->evaluate($scope));
        $divisor = Operand::int($this->right->evaluate($scope));
        return $divisor === 0 ? null : $this->applyInt($dividend, $divisor);
    }

    #[Override]
    final public function getType(): Type
    {
        return Type::option($this->left->getType());
    }

    /**
     * The int result for a divisor already known to be nonzero, or null if it still doesn't exist in int.
     */
    abstract protected function applyInt(int $dividend, int $divisor): int|null;

    /**
     * The float result for a divisor already known to be nonzero.
     */
    abstract protected function applyFloat(float $dividend, float $divisor): float;
}
