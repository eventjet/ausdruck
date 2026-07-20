<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Eventjet\Ausdruck\Parser\Span;
use Eventjet\Ausdruck\Parser\Token;
use Override;

/**
 * An operator with two operand sub-expressions and an infix spelling. Two such operators differ only in the token they
 * are spelled with, how they evaluate, and the type they give the result — everything else lives here, and printing
 * most of all: {@see Precedence::binary()} derives the operand slots from the node's level, and the level is looked up
 * from that same token, so a subclass has no way to print itself in a way the parser would read back differently.
 *
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
abstract class BinaryOperator extends Expression
{
    public function __construct(public readonly Expression $left, public readonly Expression $right)
    {
    }

    final public function __toString(): string
    {
        return Precedence::binary($this);
    }

    /**
     * How the operator is spelled in the language, e.g. `+`. Taken from the token the parser reads it as, so the
     * printer can't spell an operator differently than the lexer reads it.
     */
    final public function symbol(): string
    {
        return $this->token()->value;
    }

    #[Override]
    final public function equals(Expression $other): bool
    {
        return $other instanceof static
            && $this->left->equals($other->left)
            && $this->right->equals($other->right);
    }

    #[Override]
    final public function location(): Span
    {
        return $this->left->location()->to($this->right->location());
    }

    /**
     * The token the parser reads this operator as. Answering it is what gives the operator both its spelling and its
     * precedence level, so a new operator can't be declared without placing it in the cascade.
     */
    abstract public function token(): Token;
}
