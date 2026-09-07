<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Eventjet\Ausdruck\Formatter\Doc;

use function array_map;
use function array_reverse;
use function count;
use function sprintf;

/**
 * How a run of postfix `.`s is spelled. {@see Precedence} is its counterpart for the infix and prefix operators: both
 * exist so that a node spells itself the way the parser reads it back, and both are the only place their half of the
 * grammar is spelled at all.
 *
 * A {@see Call} and a {@see FieldAccess} each hold one link of such a run, so `a.b:int().c` is a field access over a
 * call over a variable. Spelling starts at the outermost link and walks down to whatever the run stands on, because
 * the run is what the layout is decided for: the links are where a long chain is broken onto lines of its own, and
 * that decision can't be made a link at a time.
 *
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class PostfixChain
{
    /**
     * The whole run $link ends, spelled with its base and one entry per link.
     *
     * Only a call is offered as a place to end a line. A field access is a name and nothing else, so putting one on a
     * line of its own would buy no room for what follows it and cost a line; it stays glued to whatever it reads from.
     */
    public static function doc(Call|FieldAccess $link): Doc
    {
        /** @var list<Call | FieldAccess> $links */
        $links = [];
        $base = $link;
        while ($base instanceof Call || $base instanceof FieldAccess) {
            $links[] = $base;
            $base = $base instanceof Call ? $base->target : $base->struct;
        }
        // The run is read from its last link inwards, so it comes off backwards. Turning it around once is linear,
        // where prepending each link would copy everything collected so far every time.
        $links = array_reverse($links);
        $breakable = self::isBreakable($links);
        $parts = [];
        foreach ($links as $current) {
            if (!$current instanceof Call) {
                $parts[] = Doc::text(sprintf('.%s', $current->field));
                continue;
            }
            if ($breakable) {
                $parts[] = Doc::softLine();
            }
            $parts[] = Doc::text(sprintf('.%s:%s', $current->name, $current->type));
            $parts[] = self::arguments($current);
        }
        return Doc::group(
            Precedence::parenthesizeTarget($base),
            $breakable ? Doc::indent(...$parts) : Doc::concat(...$parts),
        );
    }

    /**
     * A run is broken onto lines of its own once it calls more than once. A run that calls once has nothing to line up
     * against, so breaking it would push the call's arguments over a line end for no gain where breaking the argument
     * list itself is what buys the room:
     *
     * ```
     * items:list<int>.filter:list<int>(
     *     |item| item:int > 100,
     * )
     * ```
     *
     * rather than the chain break, which leaves the argument list to break again underneath it.
     *
     * @param list<Call | FieldAccess> $links
     */
    private static function isBreakable(array $links): bool
    {
        $calls = 0;
        foreach ($links as $link) {
            if ($link instanceof Call) {
                $calls++;
            }
        }
        return $calls > 1;
    }

    /**
     * A call's argument list. It is a comma-separated sequence like any other, with one exception: a sole argument that
     * already spells itself as a bracketed sequence keeps the parentheses tight around it, so the argument breaks and
     * the call doesn't.
     *
     * ```
     * orders:list<Order>.map:list<Row>(|order| {
     *     id: order:Order.id,
     * })
     * ```
     *
     * Breaking the argument list as well would spell the same expression with two openings and two closings for one
     * argument, and indent the struct a step further than it has to be. The exception is worth making only when the
     * argument's own spelling starts with a bracket on the line the call opened and ends with one on a line of its
     * own, because that is what makes the tight parentheses read as a single opening rather than a run-on.
     *
     * The argument's document is what answers that, by way of {@see Doc::isBracketedSequence()}, rather than a list
     * of the node types that spell themselves that way. The two are not the same question — an empty list literal is
     * a list literal, but it spells as `[]` and has no broken spelling to keep the parentheses tight around — and it
     * is the document, not the node, that knows which spelling it gives. Asking it also lets a node a consumer wrote
     * take the exception, which a list closed inside this class could never offer.
     */
    private static function arguments(Call $call): Doc
    {
        $arguments = array_map(Doc::of(...), $call->arguments);
        if (count($arguments) === 1 && $arguments[0]->isBracketedSequence()) {
            return Doc::concat(Doc::text('('), $arguments[0], Doc::text(')'));
        }
        return Doc::commaSeparated('(', $arguments, ')');
    }
}
