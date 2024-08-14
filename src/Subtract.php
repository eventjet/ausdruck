<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Eventjet\Ausdruck\Parser\Span;

use function get_debug_type;
use function gettype;
use function is_float;
use function is_int;
use function sprintf;

/**
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class Subtract extends Expression
{
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
        /** @var mixed $minuend */
        $minuend = $this->minuend->evaluate($scope);
        /** @var mixed $subtrahend */
        $subtrahend = $this->subtrahend->evaluate($scope);
        if (is_int($minuend) && is_int($subtrahend)) {
            return $minuend - $subtrahend;
        }
        if (is_float($minuend) && is_float($subtrahend)) {
            return $minuend - $subtrahend;
        }
        if (gettype($minuend) === gettype($subtrahend)) {
            throw new EvaluationError(
                sprintf(
                    'Expected operands to be of type int or float, got %s and %s',
                    get_debug_type($minuend),
                    get_debug_type($subtrahend),
                ),
            );
        }
        throw new EvaluationError(
            sprintf(
                'Expected operands to be of the same type, got %s and %s',
                get_debug_type($minuend),
                get_debug_type($subtrahend),
            ),
        );
    }

    public function equals(Expression $other): bool
    {
        return $other instanceof self
            && $this->minuend->equals($other->minuend)
            && $this->subtrahend->equals($other->subtrahend);
    }

    public function getType(): Type
    {
        return $this->minuend->getType();
    }

    public function location(): Span
    {
        return $this->minuend->location()->to($this->subtrahend->location());
    }
}
