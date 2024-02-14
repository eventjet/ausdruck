<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Eventjet\Ausdruck\Parser\Span;
use Eventjet\Ausdruck\Type\AbstractType;

use function sprintf;

/**
 * @template T of int | float
 * @extends Expression<T>
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class Subtract extends Expression
{
    /**
     * @param Expression<T> $minuend
     * @param Expression<T> $subtrahend
     */
    public function __construct(public readonly Expression $minuend, public readonly Expression $subtrahend)
    {
    }

    public function __toString(): string
    {
        /** @psalm-suppress ImplicitToStringCast */
        return sprintf('%s - %s', $this->minuend, $this->subtrahend);
    }

    /**
     * @psalm-suppress InvalidReturnType False positive
     */
    public function evaluate(Scope $scope): int|float
    {
        /**
         * @psalm-suppress InvalidOperand False positive; they are guaranteed to have the same type
         * @psalm-suppress InvalidReturnStatement False positive
         */
        return $this->minuend->evaluate($scope) - $this->subtrahend->evaluate($scope);
    }

    public function equals(Expression $other): bool
    {
        return $other instanceof self
            && $this->minuend->equals($other->minuend)
            && $this->subtrahend->equals($other->subtrahend);
    }

    public function getType(): AbstractType
    {
        return $this->minuend->getType();
    }

    public function location(): Span
    {
        return $this->minuend->location()->to($this->subtrahend->location());
    }
}
