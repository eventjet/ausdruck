<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Override;

/**
 * The sum, difference and product: the arithmetic whose result is of the operands' own type, where {@see Division}'s is
 * an option of it. The two halves pick their arithmetic the same way — from the operands' declared type, never from
 * what they evaluate to, for the reason set out there — and both narrow their operands to that type before applying it.
 *
 * Where they differ is what happens when the result doesn't exist in that type. PHP's int arithmetic isn't closed: a
 * sum, difference or product past the int range evaluates to a float rather than wrapping. A division has somewhere to
 * put that — its type is already an option, so it answers none — but these operators' type is the operand type itself,
 * so there is no value of it left to give and the only honest answer is to fail. {@see self::applyInt()} reports it the
 * same way {@see Division::applyInt()} does, by answering null for a result that isn't there; the difference is only in
 * what the two do with that null.
 *
 * Overflow is therefore checked, not observed: each operator decides in int arithmetic whether its result would leave
 * the range, and never computes the widened value. That is what lets an int-typed node promise an int — evaluating one
 * gives an int or throws, and a float can't escape under a type that says int.
 *
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
abstract class Arithmetic extends BinaryOperator
{
    #[Override]
    final public function evaluate(Scope $scope): int|float
    {
        if ($this->left->matchesType(Type::float())) {
            $left = Operand::float($this->left->evaluate($scope));
            $right = Operand::float($this->right->evaluate($scope));
            return $this->applyFloat($left, $right);
        }
        $left = Operand::int($this->left->evaluate($scope));
        $right = Operand::int($this->right->evaluate($scope));
        return $this->applyInt($left, $right) ?? throw EvaluationError::outsideIntRange($this);
    }

    #[Override]
    final public function getType(): Type
    {
        return $this->left->getType();
    }

    /**
     * The int result, or null if it doesn't exist in int. Decided without computing the widened value: the operands
     * are compared against the bound the result would have to pass, in int arithmetic that can't overflow itself.
     */
    abstract protected function applyInt(int $left, int $right): int|null;

    abstract protected function applyFloat(float $left, float $right): float;
}
