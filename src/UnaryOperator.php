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
 * left of its only operand, so the node's span can't be derived from its subtree the way a binary operator's is.
 *
 * Unlike {@see BinaryOperator}, this class carries no `@internal`: {@see Negative} published it by omission, before
 * there was a base class to lift it into, so every member declared here—inherited by `Negative` and any other
 * subclass—is public API of a published class. Psalm resolves internality from the declaring class, not the
 * subclass, so annotating the base would silently take that API away without either tool or docblock saying so.
 * That is a real constraint on what may live here, not a fact to note in passing:
 *
 * - The operand lives in a property called $expression, though every docblock here calls it the operand: `Negative`
 *   published that name first, so it is fixed.
 * - {@see self::__toString()} and {@see self::equals()} are not `final`, unlike their {@see BinaryOperator}
 *   counterparts: `Negative` published both before there was a base class to move them to, and neither can become
 *   final without risking a consumer's override.
 * - {@see self::token()} is `protected`, unlike the public {@see BinaryOperator::token()}: `Negative` never
 *   published it, so nothing forces it public, and {@see Precedence::unary()} takes the token and the operand
 *   instead of the node for exactly that reason. Being public would not have opened the hierarchy to outside
 *   subclasses anyway: {@see self::token()} returns {@see Token}, which is itself `@internal`, so nothing outside
 *   this namespace can implement the abstract method regardless of its visibility.
 *
 * Marking the hierarchy `@internal`, which would let all three become the {@see BinaryOperator} shape, is a backward
 * compatibility break and waits for the next major; tracked as eventjet/ausdruck#83.
 */
abstract class UnaryOperator extends Expression
{
    use LocationTrait;

    public function __construct(public readonly Expression $expression, Span $location)
    {
        $this->location = $location;
    }

    public function __toString(): string
    {
        return Precedence::unary($this->token(), $this->expression);
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
     * The token the parser reads this operator as, which is what gives the operator its spelling.
     *
     * @return Token::Minus
     */
    abstract protected function token(): Token;
}
