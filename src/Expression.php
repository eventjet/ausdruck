<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Eventjet\Ausdruck\Parser\Span;
use Eventjet\Ausdruck\Parser\TypeAnnotation;
use Stringable;

/**
 * @api
 */
abstract class Expression implements Stringable
{
    public function eq(self $other): Eq
    {
        return Expr::eq($this, $other);
    }

    public function neq(self $other): self
    {
        return Expr::neq($this, $other);
    }

    public function subtract(self $subtrahend): Subtract
    {
        return Expr::subtract($this, $subtrahend);
    }

    public function add(self $addend): Add
    {
        return Expr::add($this, $addend);
    }

    public function multiply(self $multiplier): Multiply
    {
        return Expr::multiply($this, $multiplier);
    }

    /**
     * The quotient is an option of the operands' type: none when the divisor evaluates to zero. See {@see Divide}.
     */
    public function divide(self $divisor): Divide
    {
        return Expr::divide($this, $divisor);
    }

    /**
     * The remainder is an option of the operands' type: none when the divisor evaluates to zero. See {@see Modulo}.
     */
    public function modulo(self $divisor): Modulo
    {
        return Expr::modulo($this, $divisor);
    }

    public function gt(self $right): Gt
    {
        return Expr::gt($this, $right);
    }

    public function lt(self $right): self
    {
        return Expr::lt($this, $right);
    }

    public function gte(self $right): self
    {
        return Expr::gte($this, $right);
    }

    public function lte(self $right): self
    {
        return Expr::lte($this, $right);
    }

    public function or_(self $other): Or_
    {
        return Expr::or_($this, $other);
    }

    public function and_(self $other): self
    {
        return Expr::and_($this, $other);
    }

    public function not(): self
    {
        return Expr::not($this);
    }

    /**
     * Unlike the other builders, this one can't check its operands: there are no declarations here to look the
     * function's signature up in, so there is nothing to check the receiver and the arguments against. The call is
     * checked against $type when it's evaluated. Parse the expression instead of building it if you want the
     * arguments checked up front.
     *
     * @param Type $type The function's return type. There's no declaration here to contradict, so it's taken as given.
     * @param list<Expression> $arguments
     */
    public function call(string $name, Type $type, array $arguments, Span|null $location = null): Call
    {
        return Expr::call(
            $this,
            $name,
            new TypeAnnotation($type, $location ?? Expr::dummySpan()),
            $arguments,
            signature: null,
            location: $location,
        );
    }

    public function matchesType(Type $type): bool
    {
        return $this->getType()->equals($type);
    }

    public function isSubtypeOf(Type $type): bool
    {
        return $this->getType()->isSubtypeOf($type);
    }

    abstract public function location(): Span;

    /**
     * @throws EvaluationError
     */
    abstract public function evaluate(Scope $scope): mixed;

    abstract public function equals(self $other): bool;

    abstract public function getType(): Type;
}
