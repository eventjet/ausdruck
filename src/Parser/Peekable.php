<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Parser;

use Generator;

/**
 * @template T
 */
final readonly class Peekable
{
    /** @var Generator<mixed, T> */
    private iterable $items;

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
        return $this->items->current();
    }

    /**
     * @return T | null
     */
    public function next(): mixed
    {
        $value = $this->items->current();
        $this->items->next();
        return $value;
    }
}
