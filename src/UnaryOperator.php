<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Eventjet\Ausdruck\Parser\Span;
use Eventjet\Ausdruck\Parser\Token;
use Override;

/**
 * An operator with one operand sub-expression and a prefix spelling. It is {@see BinaryOperator}'s counterpart, and
 * holds the same things for the same reason: two such operators differ only in the token they are spelled with, how
 * they evaluate, and the type they give the result, and printing follows from the token alone, so a subclass has no way
 * to print itself in a way the parser would read back differently.
 *
 * The one thing a unary operator has to carry that a binary one doesn't is its location: the operator stands to the
 * left of its only operand, so where it begins isn't anywhere in the tree below it.
 *
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
abstract class UnaryOperator extends Expression
{
    use LocationTrait;

    public function __construct(public readonly Expression $expression, Span $location)
    {
        $this->location = $location;
    }

    /**
     * Not final, unlike {@see BinaryOperator::__toString()}, and neither is {@see self::equals()}: {@see Negative}
     * published both before there was a base class to move them to, and marking them final now would be a backward
     * compatibility break. It costs nothing to leave them open—every operator here is a final class, so there is
     * nothing that could override them.
     */
    public function __toString(): string
    {
        return Precedence::unary($this);
    }

    /**
     * How the operator is spelled in the language, e.g. `!`. Taken from the token the parser reads it as, so the
     * printer can't spell an operator differently than the lexer reads it.
     */
    final public function symbol(): string
    {
        return $this->token()->value;
    }

    /**
     * Two operator nodes are the same expression when they are the same operator over an equal operand.
     */
    #[Override]
    public function equals(Expression $other): bool
    {
        return $other instanceof static
            && $this->expression->equals($other->expression);
    }

    /**
     * The token the parser reads this operator as, which is what gives the operator its spelling. Protected, unlike
     * {@see BinaryOperator::token()}, because nothing outside the hierarchy has anything to ask it: a binary operator's
     * token fixes its precedence level, so {@see Precedence} reads it, while the cascade has a single unary level and
     * everything spelled at it binds equally tight—{@see self::symbol()} is the only caller. That also keeps the
     * internal {@see Token} off the public surface of {@see Negative}, which is not itself internal.
     */
    abstract protected function token(): Token;
}
