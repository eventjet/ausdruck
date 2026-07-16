<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Parser;

use Generator;

use function array_key_exists;
use function count;

/**
 * A single-item-lookahead cursor over an iterable, with the ability to rewind. Items pulled from the underlying
 * generator are buffered as they are read, so {@see self::snapshot()} can record the current position and
 * {@see self::restore()} can return to it later—which is what lets a parser try one reading of the tokens ahead and
 * fall back to another. Everything already read stays available; nothing is pulled from the generator twice.
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
    private int $cursor = 0;

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
     * @return T | null
     */
    public function peek(): mixed
    {
        if ($this->cursor === count($this->buffer)) {
            if (!$this->items->valid()) {
                return null;
            }
            $this->buffer[] = $this->items->current();
            $this->items->next();
        }
        return $this->buffer[$this->cursor];
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
     */
    public function snapshot(): int
    {
        return $this->cursor;
    }

    public function restore(int $snapshot): void
    {
        $this->cursor = $snapshot;
    }
}
