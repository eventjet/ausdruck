<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Formatter;

use Eventjet\Ausdruck\Expression;

/**
 * Spells an expression back out across as many lines as it takes to stay within a column width. It is the wide
 * counterpart of printing one: `(string) $expression` is this same spelling with every line end declined, so an
 * expression that already fits formats to exactly what it prints, and one that doesn't is broken at the places its
 * nodes offer rather than rewritten.
 *
 * Formatting is a function of the tree, so the source of a stored expression is formatted by parsing it first:
 *
 * ```php
 * $formatted = ExpressionFormatter::format(ExpressionParser::parse($source, $declarations));
 * ```
 *
 * Both halves keep their own options that way — what a source may name is the parser's business, and how wide a line
 * may be is this class's.
 *
 * The result always re-parses to an equal expression: every break point a node offers sits where the parser already
 * reads whitespace, and a broken comma-separated sequence ends in a trailing comma, which the parser allows everywhere
 * it reads one.
 *
 * @api
 */
final class ExpressionFormatter
{
    /**
     * The width {@see self::format()} lays out to when it isn't told one.
     */
    public const DEFAULT_WIDTH = 80;

    /**
     * $expression spelled to fit within $width columns. A line only ends where a node offers to end one, so a run with
     * nothing to break — a long string literal, a variable with a long type annotation — is spelled past $width rather
     * than mangled.
     */
    public static function format(Expression $expression, int $width = self::DEFAULT_WIDTH): string
    {
        return Doc::of($expression)->render($width);
    }
}
