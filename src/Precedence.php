<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Eventjet\Ausdruck\Parser\ExpressionParser;

use function sprintf;

/**
 * How tightly an expression binds, on the same scale as the precedence cascade in {@see ExpressionParser}: the tighter
 * an operator's level in that cascade, the higher its case's value here. The two have to agree, because this exists only
 * to undo what the cascade does when an expression is printed back out.
 *
 * An operator prints each operand with {@see self::parenthesize()}, passing the level the parser reads that operand at:
 * {@see ExpressionParser::parseOr()} reads the right side of `||` by calling {@see ExpressionParser::parseAnd()}, so
 * `||` prints its right side at {@see self::And}. An operand looser than the level it sits at is wrapped in
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
enum Precedence: int
{
    /**
     * The loosest level, looser even than `||`: a lambda's body runs to the right until something outside stops it, so
     * `|x| x:bool` used as an operand always has to be wrapped—`(|x| x:bool).foo:bool()` would otherwise print as
     * `|x| x:bool.foo:bool()` and re-parse with the `.foo` swallowed into the body.
     */
    case Lambda = 0;
    case Or = 1;
    case And = 2;
    case Comparison = 3;
    case Additive = 4;
    case Unary = 5;
    /**
     * Calls, field accesses and the atomic expressions (literals, variables, lists, structs) all share the tightest
     * level: none of them can have an operand stolen, so none is ever parenthesized as an operand. Lambdas look atomic
     * but are not—see {@see self::Lambda}.
     */
    case Primary = 6;

    /**
     * Prints $operand as it appears in an operand slot that the parser reads at $slot, wrapping it in parentheses when
     * it binds looser than $slot and would otherwise be re-parsed into a different tree.
     */
    public static function parenthesize(Expression $operand, self $slot): string
    {
        return self::of($operand)->bindsLooserThan($slot) ? sprintf('(%s)', $operand) : (string)$operand;
    }

    /**
     * Prints $target as it appears before a postfix `.`—the receiver of a call or field access. This is the
     * {@see self::Primary} slot, so precedence alone would never wrap anything; a bare number literal is the one
     * exception, and needs parentheses for a reason the precedence cascade doesn't model. `2.abs:int()` re-tokenizes
     * the `.` as a decimal point, and `-2.abs:int()` lets the `.` bind tighter than the leading minus, so each
     * re-parses into a different tree than the `(2).foo` / `(-2).foo` receiver it was printed from. The hazard is
     * lexical, not a matter of binding, which is why it lives here rather than in {@see self::of()}.
     */
    public static function parenthesizeTarget(Expression $target): string
    {
        if ($target instanceof Literal && $target->isNumber()) {
            return sprintf('(%s)', $target);
        }
        return self::parenthesize($target, self::Primary);
    }

    /**
     * Only the nodes that bind loosely enough to ever need wrapping are named; everything else is primary-tight. The
     * default is deliberately forgiving rather than a hard error: {@see Expression} is public API, so a consumer can
     * add nodes this enum has never heard of, and an unknown node is almost always atomic—the safe reading is to
     * treat it as {@see self::Primary} rather than reject it. A node whose text runs past its own operands, though —
     * any operator, and every lambda — has to be listed here, or printing it as an operand would drop the parentheses
     * it needs. (A number literal is the one primary-tight node that still needs wrapping in a slot; that's a lexical
     * quirk of the postfix `.`, handled in {@see self::parenthesizeTarget()} rather than by a level of its own.)
     */
    private static function of(Expression $expr): self
    {
        return match (true) {
            $expr instanceof Lambda => self::Lambda,
            $expr instanceof Or_ => self::Or,
            $expr instanceof And_ => self::And,
            $expr instanceof Eq, $expr instanceof Gt => self::Comparison,
            $expr instanceof Subtract => self::Additive,
            $expr instanceof Negative => self::Unary,
            default => self::Primary,
        };
    }

    private function bindsLooserThan(self $slot): bool
    {
        return $this->value < $slot->value;
    }
}
