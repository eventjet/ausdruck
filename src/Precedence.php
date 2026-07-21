<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Eventjet\Ausdruck\Parser\ExpressionParser;
use Eventjet\Ausdruck\Parser\Token;
use LogicException;

use function sprintf;

/**
 * How tightly an expression binds, on the same scale as the precedence cascade in {@see ExpressionParser}: the tighter
 * an operator's level in that cascade, the higher its case's value here. The two have to agree, because this exists only
 * to undo what the cascade does when an expression is printed back out.
 *
 * An operand is printed with {@see self::parenthesize()}, passing the level the parser reads that operand at. A binary
 * operator picks nothing by hand: {@see self::binary()} looks the node's level up in {@see self::of()} and derives both
 * operand slots from it, so how a node prints can't disagree with how it re-parses.
 * {@see ExpressionParser::parseOr()} reads the right side of `||` by calling {@see ExpressionParser::parseAnd()}, so
 * `||` prints its right side at {@see self::And}, one level tighter than its own. An operand looser than the level it
 * sits at is wrapped in parentheses, because printing it bare would let the surrounding operator capture one of its
 * parts, and parsing the result back would then give a different tree. An operand at least as tight needs no
 * parentheses, so redundant ones — the parentheses in `(a:int - b:int) - c:int`, which the left-associative `-` would
 * have grouped that way anyway — are dropped.
 *
 * A binary operator's level comes from the token it is spelled with, so an operator can't exist without one. Grouping,
 * on the other hand, is not stored anywhere: parentheses leave no trace in the tree, so the same tree always prints the
 * same way regardless of whether it was built with them, in the builder API, or by the parser.
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
    case Multiplicative = 5;
    case Unary = 6;
    /**
     * Calls, field accesses and the atomic expressions (literals, variables, lists, structs) all share the tightest
     * level: none of them can have an operand stolen, so none is ever parenthesized as an operand. Lambdas look atomic
     * but are not—see {@see self::Lambda}.
     */
    case Primary = 7;

    /**
     * Prints a binary operator node. Its level comes from {@see self::of()}, and both operand slots follow from the
     * level alone: the right slot is one level tighter, because every level of the cascade reads its right operand by
     * calling the next one down, and the left slot is the level's {@see self::leftSlot()}. `a:int - (b:int - c:int)`
     * keeps its parentheses because the right slot is the tighter one; `(a:int - b:int) - c:int` loses them because
     * the additive left slot isn't; `(a:int === b:int) === c:bool` keeps them on either side because the comparison
     * level's left slot is tighter too.
     */
    public static function binary(BinaryOperator $operator): string
    {
        $level = self::of($operator);
        return sprintf(
            '%s %s %s',
            self::parenthesize($operator->left, $level->leftSlot()),
            $operator->symbol(),
            self::parenthesize($operator->right, $level->tighter()),
        );
    }

    /**
     * Prints a unary operator node. Its operand sits at {@see self::Unary}, the level itself rather than the tighter
     * one a binary operator's right slot gets: {@see ExpressionParser::parseUnary()} reads its operand by calling
     * itself, so a unary operator's operand may be another one—`!!a:bool`, `- -a:int`—with nothing between them.
     */
    public static function unary(UnaryOperator $operator): string
    {
        return sprintf('%s%s', $operator->symbol(), self::parenthesize($operator->expression, self::Unary));
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
     * Prints $operand as it appears in an operand slot that the parser reads at $slot, wrapping it in parentheses when
     * it binds looser than $slot and would otherwise be re-parsed into a different tree.
     */
    private static function parenthesize(Expression $operand, self $slot): string
    {
        return self::of($operand)->bindsLooserThan($slot) ? sprintf('(%s)', $operand) : (string)$operand;
    }

    /**
     * Only the nodes that bind loosely enough to ever need wrapping are named; everything else is primary-tight. The
     * default is deliberately forgiving rather than a hard error: {@see Expression} is public API, so a consumer can
     * add nodes this enum has never heard of, and an unknown node is almost always atomic—the safe reading is to
     * treat it as {@see self::Primary} rather than reject it. It is never reached by an operator of this library's
     * own, though: a {@see BinaryOperator} carries the token it is spelled with, which fixes its level in
     * {@see self::ofToken()}, and a {@see UnaryOperator} is named by its base class, the cascade having a single level
     * for all of them, so neither can be added without being placed in the cascade. (A number literal is the one
     * primary-tight node that still needs wrapping in a slot; that's a lexical quirk of the postfix `.`, handled in
     * {@see self::parenthesizeTarget()} rather than by a level of its own.)
     */
    private static function of(Expression $expr): self
    {
        return match (true) {
            $expr instanceof BinaryOperator => self::ofToken($expr->token()),
            $expr instanceof Lambda => self::Lambda,
            $expr instanceof UnaryOperator => self::Unary,
            default => self::Primary,
        };
    }

    /**
     * The level the parser reads $token at, listing the binary levels of the cascade in the same order they appear
     * there. A token that spells no binary operator can't reach this: the only caller passes what a
     * {@see BinaryOperator} answered.
     */
    private static function ofToken(Token $token): self
    {
        return match ($token) {
            Token::Or => self::Or,
            Token::And => self::And,
            Token::TripleEquals, Token::NotEquals, Token::CloseAngle, Token::OpenAngle,
            Token::GreaterThanEquals, Token::LessThanEquals => self::Comparison,
            Token::Plus, Token::Minus => self::Additive,
            Token::Asterisk, Token::Slash, Token::Percent => self::Multiplicative,
            default => throw new LogicException(sprintf('%s is not a binary operator', $token->value)),
        };
    }

    private function bindsLooserThan(self $slot): bool
    {
        return $this->value < $slot->value;
    }

    /**
     * The next tighter level: the one the parser cascade delegates to for an operand. Only the binary operator levels
     * ask for this—{@see self::ofToken()} returns nothing else—and each of them has a tighter neighbor, so the lookup
     * can't fail.
     */
    private function tighter(): self
    {
        return self::from($this->value + 1);
    }

    /**
     * The slot the parser reads a binary operator's left operand at. A while-loop level folds operands into its left
     * side at its own level — that's what makes those levels left-associative — so the left slot is the level itself.
     * The comparison level has an if-shape instead: it reads both sides one level tighter, which is what makes the six
     * comparison operators non-associative, and why its left slot is the tighter one. Associativity is a fact about
     * the level, not about the operator: operators sharing a level are parsed by the same loop or if, so they can't
     * differ in it.
     */
    private function leftSlot(): self
    {
        return $this === self::Comparison ? $this->tighter() : $this;
    }
}
