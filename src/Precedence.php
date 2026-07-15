<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Eventjet\Ausdruck\Parser\ExpressionParser;

use function sprintf;

/**
 * How tightly an expression binds, on the same scale as the precedence cascade in {@see ExpressionParser}: the tighter
 * an operator's level in that cascade, the higher its number here. The two have to agree, because this exists only to
 * undo what the cascade does when an expression is printed back out.
 *
 * An operator prints each operand with {@see self::parenthesize()}, passing the level the parser reads that operand at:
 * {@see ExpressionParser::parseOr()} reads the right side of `||` by calling {@see ExpressionParser::parseAnd()}, so
 * `||` prints its right side at {@see self::AND}. An operand looser than the level it sits at is wrapped in
 * parentheses, because printing it bare would let the surrounding operator capture one of its parts, and parsing the
 * result back would then give a different tree. An operand at least as tight needs no parentheses, so redundant ones —
 * the parentheses in `(a:int - b:int) - c:int`, which the left-associative `-` would have grouped that way anyway —
 * are dropped.
 *
 * The levels are not stored on the nodes: grouping leaves no trace in the tree, so the same tree always prints the same
 * way regardless of whether it was built with parentheses, in the builder API, or by the parser.
 *
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class Precedence
{
    /**
     * The loosest level, looser even than `||`: a lambda's body runs to the right until something outside stops it, so
     * `|x| x:bool` used as an operand always has to be wrapped—`(|x| x:bool).foo:bool()` would otherwise print as
     * `|x| x:bool.foo:bool()` and re-parse with the `.foo` swallowed into the body.
     */
    public const LAMBDA = 0;
    public const OR = 1;
    public const AND = 2;
    public const COMPARISON = 3;
    public const ADDITIVE = 4;
    public const UNARY = 5;
    /**
     * Calls, field accesses and the atomic expressions (literals, variables, lists, structs) all share the tightest
     * level: none of them can have an operand stolen, so none is ever parenthesized as an operand. Lambdas look atomic
     * but are not—see {@see self::LAMBDA}.
     */
    public const PRIMARY = 6;

    /**
     * Prints $operand as it appears in an operand slot that the parser reads at $level, wrapping it in parentheses when
     * it binds looser than $level and would otherwise be re-parsed into a different tree.
     */
    public static function parenthesize(Expression $operand, int $level): string
    {
        return self::of($operand) < $level ? sprintf('(%s)', $operand) : (string)$operand;
    }

    /**
     * Only the nodes that bind loosely enough to ever need wrapping are named; everything else is primary-tight. The
     * default is deliberately forgiving rather than a hard error: {@see Expression} is public API, so a consumer can
     * add nodes this class has never heard of, and an unknown node is almost always atomic—the safe reading is to
     * treat it as {@see self::PRIMARY} rather than reject it. A node whose text runs past its own operands, though —
     * any operator, and every lambda — has to be listed here, or printing it as an operand would drop the parentheses
     * it needs.
     */
    private static function of(Expression $expr): int
    {
        return match (true) {
            $expr instanceof Lambda => self::LAMBDA,
            $expr instanceof Or_ => self::OR,
            $expr instanceof And_ => self::AND,
            $expr instanceof Eq, $expr instanceof Gt => self::COMPARISON,
            $expr instanceof Subtract => self::ADDITIVE,
            $expr instanceof Negative => self::UNARY,
            default => self::PRIMARY,
        };
    }
}
