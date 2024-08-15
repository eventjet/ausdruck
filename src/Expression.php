<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Eventjet\Ausdruck\Parser\Span;
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

    public function subtract(self $subtrahend): Subtract
    {
        /** @var self $self */
        $self = $this;
        return Expr::subtract($self, $subtrahend);
    }

    public function gt(self $right): Gt
    {
        /** @var self $self */
        $self = $this;
        return Expr::gt($self, $right);
    }

    public function or_(self $other): Or_
    {
        /** @var self $self */
        $self = $this;
        return Expr::or_($self, $other);
    }

    public function and_(self $other): self
    {
        /** @var self $self */
        $self = $this;
        return Expr::and_($self, $other);
    }

    /**
     * @param list<Expression> $arguments
     */
    public function call(string $name, Type $type, array $arguments, Span|null $location = null): Call
    {
        return Expr::call($this, $name, $type, $arguments, $location);
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
