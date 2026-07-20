<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Test\Unit\Parser;

use ArrayIterator;
use Eventjet\Ausdruck\Parser\Peekable;
use Generator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PeekableTest extends TestCase
{
    private const UNSCANNABLE = '<unscannable>';

    /**
     * Stands in for the tokenizer: it yields each item until it reaches one it can't handle, and throws when it gets
     * there. {@see self::UNSCANNABLE} is that item, and it throws at exactly the position it occupies, so what has
     * already been yielded is what a caller could have read before the failure.
     *
     * @param list<string> $items
     * @return Generator<int, string>
     */
    private static function scanning(array $items): Generator
    {
        foreach ($items as $item) {
            if ($item === self::UNSCANNABLE) {
                throw new RuntimeException('boom');
            }
            yield $item;
        }
    }

    /**
     * A generator that has thrown is finished for good, so the item it failed on can never be produced. Reporting that
     * as end of input would turn a broken stream into a complete one, and a caller that stops at the end—see
     * {@see \Eventjet\Ausdruck\Parser\ExpressionParser::parseComplete()}—would accept input it never managed to read.
     */
    public function testAFailedPullIsRaisedAgainRatherThanReadingAsEndOfInput(): void
    {
        $p = new Peekable(self::scanning(['a', 'b', self::UNSCANNABLE]));

        self::assertSame('a', $p->next());
        self::assertSame('b', $p->next());
        try {
            $p->peek();
            self::fail('Expected the first peek past the last item to throw');
        } catch (RuntimeException) {
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('boom');

        $p->peek();
    }

    /**
     * Rewinding past a failed pull is what speculative parsing does when it gives up on a reading. The items already
     * buffered are still there to be read again; only the one that never arrived still fails.
     */
    public function testRestoreReplaysTheBufferAndThenFailsAgain(): void
    {
        $p = new Peekable(self::scanning(['a', 'b', self::UNSCANNABLE]));

        $snapshot = $p->snapshot();
        $p->next();
        $p->next();
        try {
            $p->peek();
        } catch (RuntimeException) {
        }

        $p->restore($snapshot);

        self::assertSame('a', $p->next());
        self::assertSame('b', $p->next());
        $this->expectException(RuntimeException::class);
        $p->peek();
    }

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

    public function testPeekAheadLooksPastTheNextItemWithoutMovingTheCursor(): void
    {
        $p = new Peekable(new ArrayIterator(['a', 'b', 'c']));

        self::assertSame('b', $p->peek(1));
        self::assertSame('c', $p->peek(2));
        self::assertNull($p->peek(3));
        self::assertSame('a', $p->next());
        self::assertSame('c', $p->peek(1));
    }
}
