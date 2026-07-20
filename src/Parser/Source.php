<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Parser;

/**
 * The characters of an expression, and where in it the reader stands.
 *
 * A scanner reads through {@see self::take()} and never touches a line or column of its own, so there is exactly one
 * statement of what moving past a character means—{@see self::take()}'s newline rule—and a scanner cannot get it wrong
 * by forgetting it. A string literal crossing a line is handled by construction rather than by a branch that repeats
 * the rule.
 *
 * Positions are read, not computed: {@see self::position()} is where the next character will be read, and
 * {@see self::spanFrom()} ends a token at the last character actually taken, so no caller has to reconstruct an end
 * from a cursor that has already moved past it.
 *
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class Source
{
    /** @var Peekable<string> */
    private readonly Peekable $chars;
    /** @var positive-int */
    private int $line = 1;
    /** @var positive-int */
    private int $column = 1;
    /**
     * Where the character most recently taken was written. A token's extent ends there, so it survives the cursor
     * moving on. Before anything is taken it is the start of the input, which is where the cursor is anyway.
     *
     * @var positive-int
     */
    private int $takenLine = 1;
    /** @var positive-int */
    private int $takenColumn = 1;

    /**
     * @param iterable<mixed, string> $chars
     */
    public function __construct(iterable $chars)
    {
        $this->chars = new Peekable($chars);
    }

    /**
     * @param non-negative-int $ahead How far past the next character to look: peek() shows the next one, peek(1) the
     *     one after it.
     * @phpstan-impure
     */
    public function peek(int $ahead = 0): string|null
    {
        return $this->chars->peek($ahead);
    }

    /**
     * Reads the next character and moves past it. The only place that knows a newline starts a line and everything
     * else widens one.
     *
     * @phpstan-impure
     */
    public function take(): string|null
    {
        $char = $this->chars->next();
        if ($char === null) {
            return null;
        }
        $this->takenLine = $this->line;
        $this->takenColumn = $this->column;
        if ($char === "\n") {
            $this->line++;
            $this->column = 1;
        } else {
            $this->column++;
        }
        return $char;
    }

    /**
     * Where the next character will be read—the start of a token that has not been scanned yet.
     */
    public function position(): Position
    {
        return new Position($this->line, $this->column);
    }

    /**
     * The extent of everything taken since $start, ending on the last character taken rather than on the one that
     * stopped the scan.
     */
    public function spanFrom(Position $start): Span
    {
        return new Span($start->line, $start->column, $this->takenLine, $this->takenColumn);
    }
}
