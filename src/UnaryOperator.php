<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Eventjet\Ausdruck\Formatter\Doc;
use Eventjet\Ausdruck\Formatter\HasDoc;
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
 * {@see self::token()} is `protected`, unlike the public {@see BinaryOperator::token()}: {@see Precedence::unary()}
 * takes the token and the operand rather than the node, so nothing outside this namespace needs it. Making it public
 * would not open the hierarchy to outside subclasses anyway: it returns {@see Token}, which is itself `@internal`, so
 * nothing outside this namespace can implement the abstract method regardless of its visibility.
 *
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
abstract class UnaryOperator extends Expression implements HasDoc
{
    use LocationTrait;

    public function __construct(public readonly Expression $expression, Span $location)
    {
        $this->location = $location;
    }

    final public function __toString(): string
    {
        return $this->doc()->flat();
    }

    #[Override]
    final public function doc(): Doc
    {
        return Precedence::unary($this->token(), $this->expression);
    }

    /**
     * Two operator nodes are the same expression when they are the same operator over an equal operand.
     */
    #[Override]
    final public function equals(Expression $other): bool
    {
        return $other instanceof static
            && $this->expression->equals($other->expression);
    }

    /**
     * The token the parser reads this operator as, which is what gives the operator its spelling.
     *
     * @return Token::Minus|Token::Not
     */
    abstract protected function token(): Token;
}
