<?php

declare(strict_types=1);
namespace Eventjet\Ausdruck\Test\Unit\Fixtures;

final class Bag {
    /**
     * @param list<Item> $items
     */
    public function __construct(public readonly array $items)
    {
    }
}
