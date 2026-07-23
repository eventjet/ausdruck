<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Eventjet\Ausdruck\Parser\Span;
use Eventjet\Ausdruck\Parser\TypeAnnotation;
use Stringable;

/**
 * The public builder surface for expressions. Every combinator here returns {@see self}: the concrete node each one
 * builds ({@see Add}, {@see Comparison}, {@see Call}, and the rest) is @internal, and this class is @api, so naming one
 * as a return type would leak an internal symbol into the public surface. Returning self keeps the surface honest and
 * lets the builders chain uniformly, whatever node is underneath.
 *
 * @api
 */
abstract class Expression implements Stringable
{
    final public function eq(self $other): self
    {
        return Expr::eq($this, $other);
    }

    final public function neq(self $other): self
    {
        return Expr::neq($this, $other);
    }

    final public function subtract(self $subtrahend): self
    {
        return Expr::subtract($this, $subtrahend);
    }

    final public function add(self $addend): self
    {
        return Expr::add($this, $addend);
    }

    final public function multiply(self $multiplier): self
    {
        return Expr::multiply($this, $multiplier);
    }

    /**
     * The quotient is an option of the operands' type: none when the divisor evaluates to zero. See {@see Divide}.
     */
    final public function divide(self $divisor): self
    {
        return Expr::divide($this, $divisor);
    }

    /**
     * The remainder is an option of the operands' type: none when the divisor evaluates to zero. See {@see Modulo}.
     */
    final public function modulo(self $divisor): self
    {
        return Expr::modulo($this, $divisor);
    }

    final public function gt(self $right): self
    {
        return Expr::gt($this, $right);
    }

    final public function lt(self $right): self
    {
        return Expr::lt($this, $right);
    }

    final public function gte(self $right): self
    {
        return Expr::gte($this, $right);
    }

    final public function lte(self $right): self
    {
        return Expr::lte($this, $right);
    }

    final public function or_(self $other): self
    {
        return Expr::or_($this, $other);
    }

    final public function and_(self $other): self
    {
        return Expr::and_($this, $other);
    }

    final public function not(): self
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
    final public function call(string $name, Type $type, array $arguments, Span|null $location = null): self
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

    final public function matchesType(Type $type): bool
    {
        return $this->getType()->equals($type);
    }

    final public function isSubtypeOf(Type $type): bool
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
