<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Test\Unit\Parser;

use ArrayIterator;
use Eventjet\Ausdruck\Parser\Peekable;
use PHPUnit\Framework\TestCase;

final class PeekableTest extends TestCase
{
    public function testNextOnly(): void
    {
        $p = new Peekable(new ArrayIterator(['a', 'b', 'c']));

        self::assertSame('a', $p->next());
        self::assertSame('b', $p->next());
        self::assertSame('c', $p->next());
        self::assertNull($p->next());
    }

    public function testPeekAndNext(): void
    {
        $p = new Peekable(new ArrayIterator(['a', 'b', 'c']));

        self::assertSame('a', $p->peek());
        self::assertSame('a', $p->next());
        self::assertSame('b', $p->peek());
        self::assertSame('b', $p->next());
        self::assertSame('c', $p->peek());
        self::assertSame('c', $p->next());
        self::assertNull($p->peek());
        self::assertNull($p->next());
    }

    public function testNextEmpty(): void
    {
        $p = new Peekable(new ArrayIterator([]));

        /** @phpstan-ignore-next-line Wow, PHPStan, you're actually really smart. But I want to test it anyway. */
        self::assertNull($p->next());
    }

    public function testPeekEmpty(): void
    {
        $p = new Peekable(new ArrayIterator([]));

        /** @phpstan-ignore-next-line Wow, PHPStan, you're actually really smart. But I want to test it anyway. */
        self::assertNull($p->peek());
    }
}
