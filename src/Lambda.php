<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Eventjet\Ausdruck\Parser\Span;

use function array_map;
use function implode;
use function sprintf;

/**
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class Lambda extends Expression
{
    use LocationTrait;

    /**
     * @param list<string> $parameters
     */
    public function __construct(public readonly Expression $body, public readonly array $parameters, Span $location)
    {
        $this->location = $location;
    }

    public function __toString(): string
    {
        return sprintf('|%s| %s', implode(', ', $this->parameters), $this->body);
    }

    /**
     * @return callable(Scope): mixed
     */
    public function evaluate(Scope $scope): callable
    {
        return function (mixed ...$params) use ($scope): mixed {
            $localVars = [];
            foreach ($this->parameters as $index => $parameter) {
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

    public function getType(): Type
    {
        return Type::func($this->body->getType(), array_map(static fn(string $_name) => Type::any(), $this->parameters));
    }
}
