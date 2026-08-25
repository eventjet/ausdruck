<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Eventjet\Ausdruck\Formatter\Doc;
use Eventjet\Ausdruck\Formatter\HasDoc;
use Eventjet\Ausdruck\Parser\Span;
use Override;

use function array_map;
use function implode;
use function sprintf;

/**
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class Lambda extends Expression implements HasDoc
{
    use LocationTrait;

    /**
     * @param list<string> $parameters
     */
    public function __construct(public readonly Expression $body, public readonly array $parameters, Span $location)
    {
        $this->location = $location;
    }

    /**
     * A lambda with no parameters prints as `|| body`, and the lexer reads `||` back as the or operator rather than an
     * empty parameter list, so that one shape doesn't round-trip. The parser can't produce such a lambda for the same
     * reason it can't read one; only {@see Expr::lambda()} can, by being passed no parameter names.
     */
    public function __toString(): string
    {
        return $this->doc()->flat();
    }

    /**
     * A lambda offers no line end of its own: the parameter list is short by nature and the body has to start on the
     * same line as the closing `|`, so the only places a lambda breaks are the ones its body offers.
     */
    #[Override]
    public function doc(): Doc
    {
        return Doc::concat(
            Doc::text(sprintf('|%s| ', implode(', ', $this->parameters))),
            Doc::of($this->body),
        );
    }

    /**
     * @return callable(Scope): mixed
     */
    #[Override]
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

    #[Override]
    public function equals(Expression $other): bool
    {
        return $other instanceof self
            && $this->parameters === $other->parameters
            && $this->body->equals($other->body);
    }

    /**
     * A lambda is always an argument -- never the outermost signature of a declaration -- so its variables, if its
     * body's type has any, belong to whatever encloses it rather than to the lambda itself: {@see Type::func()}
     * never claims a binder of its own, so building the lambda's type through it, the same door every function type
     * is built through, already leaves those variables shared with whatever goes on to quantify them.
     */
    #[Override]
    public function getType(): Type
    {
        return Type::func(
            $this->body->getType(),
            array_map(static fn(string $_name) => Type::any(), $this->parameters),
        );
    }
}
