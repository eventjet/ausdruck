<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Test\Unit\Fixtures;

final class Item
{
    public function __construct(public readonly string $name)
    {
    }
}
