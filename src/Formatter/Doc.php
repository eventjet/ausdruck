<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Formatter;

use Eventjet\Ausdruck\Expression;

use function array_pop;
use function array_reverse;
use function count;
use function str_repeat;
use function strlen;

/**
 * How an expression is spelled, with the places a line may end left undecided. A node builds one of these instead of a
 * string, and the same document is then rendered twice over: {@see self::flat()} spells it on one line, which is what
 * every node's `__toString()` answers, and {@see self::render()} spells it to a column width, which is what
 * {@see ExpressionFormatter} answers. The two can't disagree about anything but line ends, because they read the same
 * document — a node has no way to describe a spelling that only one of them would produce.
 *
 * A {@see DocKind::Group} is the unit of that decision: the renderer measures the group's flat spelling against what is
 * left of the current line, and every {@see DocKind::Line} directly inside it either stays flat or becomes a line end,
 * all of them together. A group nested inside a broken one is measured again from its own starting column, so an
 * argument list that breaks doesn't drag its arguments apart as well.
 *
 * Width is counted in bytes rather than characters. Counting characters would mean `ext-mbstring`, a requirement this
 * package doesn't otherwise have, for a purely cosmetic gain: the only expressions the two counts disagree about are
 * ones holding non-ASCII string literals, and the disagreement makes such an expression break a little earlier than it
 * had to.
 *
 * A string literal holding a line break is counted the same way, by how long it is rather than by where it leaves the
 * cursor, and errs in the same direction: everything after it is measured as if the line were longer than it is, so
 * the expression around it breaks a little earlier than it had to.
 *
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class Doc
{
    private const INDENT = 4;

    /**
     * @param array<self> $children The children in the order they are spelled. Only that order is read, never the keys.
     */
    private function __construct(
        private readonly DocKind $kind,
        private readonly string $text,
        private readonly array $children,
        private readonly bool $isBracketedSequence = false,
    ) {
    }

    /**
     * The document $expression is spelled by. A node describes its own layout by implementing {@see HasDoc}; anything
     * else is taken as it prints, which is the right reading for the nodes with nothing to break — a variable, a scalar
     * literal — and the only available one for a node a consumer added, {@see Expression} being public API.
     */
    public static function of(Expression $expression): self
    {
        return $expression instanceof HasDoc ? $expression->doc() : self::text((string)$expression);
    }

    public static function text(string $text): self
    {
        return new self(DocKind::Text, $text, []);
    }

    /**
     * $docs one after another. The concatenation is a bracketed sequence when the document it ends in is one, which is
     * what carries the mark out of a lambda's body past the `|item| ` that introduces it: what a reader sees last is
     * still the closing bracket of a sequence.
     */
    public static function concat(self ...$docs): self
    {
        $inherited = $docs !== [] && $docs[count($docs) - 1]->isBracketedSequence;
        return new self(DocKind::Concat, '', $docs, $inherited);
    }

    public static function group(self ...$docs): self
    {
        return new self(DocKind::Group, '', $docs);
    }

    /**
     * $docs, with every line the enclosing group ends inside them indented one step further.
     */
    public static function indent(self ...$docs): self
    {
        return new self(DocKind::Indent, '', $docs);
    }

    /**
     * A place the enclosing group may end a line, spelled as a space when it doesn't.
     */
    public static function line(): self
    {
        return new self(DocKind::Line, ' ', []);
    }

    /**
     * A place the enclosing group may end a line, spelled as nothing when it doesn't — the break a bracket opens and
     * closes with, where the flat spelling has no space.
     */
    public static function softLine(): self
    {
        return new self(DocKind::Line, '', []);
    }

    /**
     * $content between a pair of brackets, breaking as a unit: either all three stay on one line, or $content is
     * indented onto lines of its own and $close returns to the column $open started at.
     */
    public static function bracket(string $open, self $content, string $close): self
    {
        return self::group(
            self::text($open),
            self::indent(self::softLine(), $content),
            self::softLine(),
            self::text($close),
        );
    }

    /**
     * $items between a pair of brackets, separated by commas: `(a, b)` flat, and one item to a line with a trailing
     * comma when broken. The trailing comma is what makes the broken spelling re-parse — the parser allows one
     * wherever it reads a comma-separated sequence — and what keeps adding an item later to one line of a diff.
     *
     * An empty sequence is spelled as the two brackets and nothing between them, rather than as a bracket with no
     * content: there is nothing to put on a line of its own, so there is no reason to offer the break.
     *
     * Both shapes are marked, which is what {@see self::isBracketedSequence()} goes on to answer. This is the only
     * place a comma-separated sequence between brackets is built, so marking it here is what keeps the answer tied to
     * the spelling. The empty shape is marked too: it is still a pair of brackets something can be laid out tight
     * against, and the tight spelling is the only one it has.
     *
     * @param list<self> $items
     */
    public static function commaSeparated(string $open, array $items, string $close): self
    {
        if ($items === []) {
            return self::text($open . $close)->asBracketedSequence();
        }
        $parts = [];
        foreach ($items as $item) {
            if ($parts !== []) {
                $parts[] = self::text(',');
                $parts[] = self::line();
            }
            $parts[] = $item;
        }
        $parts[] = self::whenBroken(',');
        return self::bracket($open, self::concat(...$parts), $close)->asBracketedSequence();
    }

    /**
     * $text, but only once the enclosing group has broken.
     */
    private static function whenBroken(string $text): self
    {
        return new self(DocKind::WhenBroken, $text, []);
    }

    /**
     * Whether $group, followed by whatever is already queued behind it, reaches the end of the line within $remaining
     * columns. $group is measured flat, because that is the spelling being decided on; the queued commands are measured
     * in the mode they were queued with, so an outer group that has already broken ends the line at its next break
     * point, and everything up to there is what has to fit.
     *
     * Indentation is not tracked here. A line end reached in broken mode answers the question immediately, and one
     * reached flat isn't a line end at all, so no indentation is ever spelled during a measurement.
     *
     * @param list<array{int, DocMode, self}> $queued
     */
    private static function fits(self $group, array $queued, int $remaining): bool
    {
        /** @var list<array{DocMode, self}> $commands */
        $commands = [[DocMode::Flat, $group]];
        $index = count($queued);
        while ($remaining >= 0) {
            if ($commands === []) {
                if ($index === 0) {
                    return true;
                }
                $index--;
                [, $queuedMode, $queuedDoc] = $queued[$index];
                $commands[] = [$queuedMode, $queuedDoc];
                continue;
            }
            [$mode, $doc] = array_pop($commands);
            if ($doc->kind->hasChildren()) {
                foreach (array_reverse($doc->children) as $child) {
                    $commands[] = [$mode, $child];
                }
                continue;
            }
            if ($doc->kind === DocKind::Line && $mode === DocMode::Broken) {
                return true;
            }
            if ($doc->kind === DocKind::WhenBroken && $mode === DocMode::Flat) {
                continue;
            }
            $remaining -= strlen($doc->text);
        }
        return false;
    }

    /**
     * Whether this document spells a comma-separated sequence between brackets — `[a, b]` flat, and an opening bracket
     * on the line it starts on with the closing one on a line of its own when broken. A caller with something to lay
     * out tight against such a spelling asks the document rather than asking what kind of node produced it: the mark
     * is put on where the spelling is built, so it can't come to describe a node that spells itself some other way,
     * which is what a list of node types drifts into.
     */
    public function isBracketedSequence(): bool
    {
        return $this->isBracketedSequence;
    }

    /**
     * The document on one line, however long that line turns out to be. No group is measured, so this is linear in the
     * size of the document, and it is the spelling every node's `__toString()` answers.
     */
    public function flat(): string
    {
        return $this->renderTo(null);
    }

    /**
     * The document laid out to fit within $width columns, breaking the groups that don't. A group with no break points
     * — a long string literal, a variable with a long type annotation — is spelled past the width rather than mangled,
     * so $width is where lines are preferred to end, not a guarantee about where they do.
     *
     * @param positive-int $width
     */
    public function render(int $width): string
    {
        return $this->renderTo($width);
    }

    /**
     * This document with the mark on. {@see self::bracket()} can't put it there itself: it also spells the parentheses
     * that go around an operand loose enough to need them, and those hold one expression rather than a sequence, so a
     * caller laying something out tight against them would be reading a bracket that isn't the sequence's own.
     */
    private function asBracketedSequence(): self
    {
        return new self($this->kind, $this->text, $this->children, true);
    }

    /**
     * Walks the document with an explicit stack of commands — each of them a document to spell, the indentation to
     * spell its line ends at, and the {@see DocMode} its group settled on — rather than by recursion, so rendering
     * adds no stack depth of its own on top of what building the document already used. The mode the walk starts in
     * is never read: every {@see DocKind::Line} this package builds is built inside a group, and a group settles its
     * own mode.
     *
     * A null $width means no group is ever measured and every one of them stays flat, which is {@see self::flat()}.
     */
    private function renderTo(int|null $width): string
    {
        $out = '';
        $column = 0;
        /** @var list<array{int, DocMode, self}> $commands */
        $commands = [[0, DocMode::Flat, $this]];
        while ($commands !== []) {
            [$indent, $mode, $doc] = array_pop($commands);
            if ($doc->kind === DocKind::Group) {
                $mode = $width !== null && !self::fits($doc, $commands, $width - $column)
                    ? DocMode::Broken
                    : DocMode::Flat;
            }
            if ($doc->kind->hasChildren()) {
                $childIndent = $doc->kind === DocKind::Indent ? $indent + self::INDENT : $indent;
                foreach (array_reverse($doc->children) as $child) {
                    $commands[] = [$childIndent, $mode, $child];
                }
                continue;
            }
            if ($doc->kind === DocKind::Line && $mode === DocMode::Broken) {
                $out .= "\n" . str_repeat(' ', $indent);
                $column = $indent;
                continue;
            }
            if ($doc->kind === DocKind::WhenBroken && $mode === DocMode::Flat) {
                continue;
            }
            $out .= $doc->text;
            $column += strlen($doc->text);
        }
        return $out;
    }
}
