<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use LogicException;

use function implode;
use function sprintf;

/**
 * @template T
 * @extends Expression<callable(Scope): T>
 */
final readonly class Lambda extends Expression
{
    /**
     * @param Expression<T> $body
     * @param list<string> $parameters
     */
    public function __construct(public Expression $body, public array $parameters = [])
    {
    }

    public function __toString(): string
    {
        /** @psalm-suppress ImplicitToStringCast */
        return sprintf('|%s| %s', implode(', ', $this->parameters), $this->body);
    }

    /**
     * @return callable(Scope): T
     */
    public function evaluate(Scope $scope): callable
    {
        return function (mixed ...$params) use ($scope): mixed {
            $localVars = [];
            foreach ($this->parameters as $index => $parameter) {
                /** @psalm-suppress MixedAssignment */
                $localVars[$parameter] = $params[$index];
            }
            return $this->body->evaluate($scope->sub($localVars));
        };
    }

    public function equals(Expression $other): bool
    {
        return $other instanceof self
            && $this->parameters === $other->parameters
            && $this->body->equals($other->body);
    }

    public function getType(): never
    {
        /** @infection-ignore-all */
        throw new LogicException('Not implemented');
    }
}
