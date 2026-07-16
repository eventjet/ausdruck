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

    public function testRestoreRewindsToASnapshot(): void
    {
        $p = new Peekable(new ArrayIterator(['a', 'b', 'c']));

        self::assertSame('a', $p->next());
        $snapshot = $p->snapshot();
        self::assertSame('b', $p->next());
        self::assertSame('c', $p->next());

        $p->restore($snapshot);

        self::assertSame('b', $p->peek());
        self::assertSame('b', $p->next());
        self::assertSame('c', $p->next());
        self::assertNull($p->next());
    }

    public function testPreviousIsTheLastConsumedItem(): void
    {
        $p = new Peekable(new ArrayIterator(['a', 'b']));

        self::assertNull($p->previous());
        $p->next();
        self::assertSame('a', $p->previous());
        $p->next();
        self::assertSame('b', $p->previous());
    }

    public function testPreviousFollowsARestore(): void
    {
        $p = new Peekable(new ArrayIterator(['a', 'b', 'c']));

        $p->next();
        $snapshot = $p->snapshot();
        $p->next();
        self::assertSame('b', $p->previous());

        $p->restore($snapshot);

        self::assertSame('a', $p->previous());
    }

    public function testPeekEmpty(): void
    {
        $p = new Peekable(new ArrayIterator([]));

        /** @phpstan-ignore-next-line Wow, PHPStan, you're actually really smart. But I want to test it anyway. */
        self::assertNull($p->peek());
    }
}
