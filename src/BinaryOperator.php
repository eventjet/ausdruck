<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Eventjet\Ausdruck\Parser\Span;
use Override;

/**
 * An operator with two operand sub-expressions and an infix spelling. Two such operators differ only in their symbol,
 * how they evaluate, and the type they give the result — everything else lives here, and printing most of all:
 * {@see Precedence::binary()} derives the operand slots from the node's level, looked up in the same place that
 * parenthesizes the node when it is itself an operand, so a subclass has no way to print itself in a way the parser
 * would read back differently.
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
     * How the operator is spelled in the language, e.g. `+`.
     */
    abstract public function symbol(): string;
}
