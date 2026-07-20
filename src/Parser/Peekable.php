<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Parser;

use Generator;
use Throwable;

use function array_key_exists;
use function count;

/**
 * A lookahead cursor over an iterable, with the ability to rewind. Items pulled from the underlying
 * generator are buffered as they are read, so {@see self::snapshot()} can record the current position and
 * {@see self::restore()} can return to it later—which is what lets a parser try one reading of the tokens ahead and
 * fall back to another. Everything already read stays available, nothing is pulled from the generator twice, and
 * nothing is pulled before a caller asks for it—a generator that throws only does so once the item it fails on is
 * actually wanted. Rewinding past such a failure replays the buffered items and fails again on reaching it: the item
 * never arrived, so the stream is short one item rather than at its end, and it says so however often it is asked.
 *
 * The cursor only moves where it's told; deciding when a reading has failed, and rewinding if it has, is the caller's
 * business.
 *
 * @template T
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class Peekable
{
    /** @var Generator<mixed, T> */
    private readonly Generator $items;
    /** @var list<T> The items pulled from the generator so far; {@see self::$cursor} indexes into it. */
    private array $buffer = [];
    /** @var non-negative-int */
    private int $cursor = 0;
    /**
     * What the generator threw, if it did. A generator that throws is finished for good, so the item it failed on will
     * never arrive; keeping the failure is what lets {@see self::peek()} say so again instead of reporting that the
     * input ended there.
     */
    private Throwable|null $failure = null;

    /**
     * @param iterable<mixed, T> $items
     */
    public function __construct(iterable $items)
    {
        $this->items = self::toGenerator($items);
    }

    /**
     * @template V
     * @param iterable<mixed, V> $items
     * @return Generator<int, V>
     */
    private static function toGenerator(iterable $items): Generator
    {
        foreach ($items as $item) {
            yield $item;
        }
    }

    /**
     * Impure, though it looks like a plain read: it is what pulls from the generator, so a call whose result is
     * thrown away still buffers an item, still runs whatever the generator does to produce it, and still throws if
     * that fails. Two calls agree only while the cursor stays put.
     *
     * @param non-negative-int $ahead How far past the next item to look: peek() shows the next item, peek(1) the one
     *     after it.
     * @return T | null
     * @phpstan-impure
     */
    public function peek(int $ahead = 0): mixed
    {
        $target = $this->cursor + $ahead;
        while (count($this->buffer) <= $target) {
            if ($this->failure !== null) {
                throw $this->failure;
            }
            try {
                // A generator stops on the item it yielded, so reading the next one means advancing past the last one
                // buffered—except on the first pass, when nothing has been yielded yet. Advancing here, once an item is
                // actually asked for, rather than right after buffering one, is what keeps the generator from running
                // ahead of the caller: the tokenizer scans the token that was peeked and not the text after it, so a
                // mistake further right can't throw before the parser has reported the one it already found.
                if ($this->buffer !== []) {
                    $this->items->next();
                }
                if (!$this->items->valid()) {
                    return null;
                }
                $this->buffer[] = $this->items->current();
            } catch (Throwable $e) {
                $this->failure = $e;
                throw $e;
            }
        }
        return $this->buffer[$target];
    }

    /**
     * Advances past the current item, so subsequent peeks return the next one.
     *
     * @return T | null
     * @phpstan-impure
     */
    public function next(): mixed
    {
        $value = $this->peek();
        if ($value !== null) {
            $this->cursor++;
        }
        return $value;
    }

    /**
     * @return T | null
     */
    public function previous(): mixed
    {
        $index = $this->cursor - 1;
        return array_key_exists($index, $this->buffer) ? $this->buffer[$index] : null;
    }

    /**
     * The current position, to be handed back to {@see self::restore()}.
     *
     * @return non-negative-int
     */
    public function snapshot(): int
    {
        return $this->cursor;
    }

    /**
     * @param non-negative-int $snapshot
     */
    public function restore(int $snapshot): void
    {
        $this->cursor = $snapshot;
    }
}
