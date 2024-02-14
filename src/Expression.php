<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Eventjet\Ausdruck\Parser\Span;
use Eventjet\Ausdruck\Type\AbstractType;
use Stringable;

/**
 * @template-covariant T
 * @api
 */
abstract class Expression implements Stringable
{
    /**
     * @param self<mixed> $other
     */
    public function eq(self $other): Eq
    {
        return Expr::eq($this, $other);
    }

    /**
     * @template U of int | float
     * @param Expression<U> $subtrahend
     * @return Subtract<U>
     */
    public function subtract(self $subtrahend): Subtract
    {
        /** @var self<U> $self */
        $self = $this;
        return Expr::subtract($self, $subtrahend);
    }

    /**
     * @template U of int | float
     * @param Expression<U> $right
     * @return Gt<U>
     */
    public function gt(self $right): Gt
    {
        /** @var self<U> $self */
        $self = $this;
        return Expr::gt($self, $right);
    }

    /**
     * @param self<bool> $other
     */
    public function or_(self $other): Or_
    {
        /** @var self<bool> $self */
        $self = $this;
        return Expr::or_($self, $other);
    }

    /**
     * @template U
     * @param list<Expression<mixed>> $arguments
     * @param AbstractType<U> $type
     * @return Call<U>
     */
    public function call(string $name, AbstractType $type, array $arguments, Span|null $location = null): Call
    {
        return Expr::call($this, $name, $type, $arguments, $location);
    }

    /**
     * @template U
     * @param AbstractType<U> $type
     * @psalm-assert-if-true self<U> $this
     * @phpstan-assert-if-true self<U> $this
     */
    public function matchesType(AbstractType $type): bool
    {
        return $this->getType()->equals($type);
    }

    abstract public function location(): Span;

    /**
     * @return T
     * @throws EvaluationError
     */
    abstract public function evaluate(Scope $scope): mixed;

    /**
     * @param Expression<mixed> $other
     */
    abstract public function equals(self $other): bool;

    /**
     * @return AbstractType<T>
     */
    abstract public function getType(): AbstractType;
}
