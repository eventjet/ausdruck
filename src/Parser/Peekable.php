<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Parser;

use Generator;

/**
 * @template T
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class Peekable
{
    /** @var Generator<mixed, T> */
    private readonly iterable $items;
    /** @var T | null */
    private mixed $previous = null;

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
        $this->previous = $value;
        $this->items->next();
        return $value;
    }

    /**
     * @return T | null
     */
    public function previous(): mixed
    {
        return $this->previous;
    }
}
