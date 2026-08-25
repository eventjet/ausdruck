<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Formatter;

/**
 * What a {@see Doc} node is. The set is closed and deliberately small: everything a node knows about laying itself out
 * is expressed by combining these six, so the renderer is the only place that decides where a line ends.
 *
 * {@see self::Text} is the only kind that always spells the same thing. {@see self::Line} and {@see self::WhenBroken}
 * spell one thing inside a group that fits and another inside one that didn't, and {@see self::Group} is what decides
 * which of the two a nested node is in. {@see self::Concat} and {@see self::Indent} only carry children.
 *
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
enum DocKind
{
    case Text;
    case Concat;
    /**
     * A candidate for staying on one line. The renderer measures the group's flat spelling against what is left of the
     * line and puts the whole group in one mode or the other; a group nested inside a broken one is measured again, so
     * breaking an outer group doesn't force the inner ones to break too.
     */
    case Group;
    case Indent;
    /**
     * A place the enclosing group may end a line. Flat, it spells its text — a space for {@see Doc::line()}, nothing
     * for {@see Doc::softLine()}; broken, it spells a newline and the indentation of whatever follows.
     */
    case Line;
    /**
     * Text that only exists once the enclosing group broke, which is what lets a broken argument list, list literal or
     * struct literal end in a trailing comma that the flat spelling doesn't have.
     */
    case WhenBroken;
}
