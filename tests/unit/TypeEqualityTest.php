<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Test\Unit;

use Eventjet\Ausdruck\Parser\SyntaxError;
use Eventjet\Ausdruck\Parser\TypeError;
use Eventjet\Ausdruck\Parser\TypeParser;
use Eventjet\Ausdruck\Parser\Types;
use Eventjet\Ausdruck\Type;
use PHPUnit\Framework\TestCase;

final class TypeEqualityTest extends TestCase
{
    /**
     * @return iterable<int, array{string, string}>
     */
    public static function equal(): iterable
    {
        yield ['int', 'int'];
    }

    /**
     * @return iterable<int, array{string, string}>
     */
    public static function notEqual(): iterable
    {
        yield ['fn(string) -> int', 'fn(string) -> string'];
        yield ['fn(string) -> int', 'fn(int) -> int'];
    }

    private static function fromString(string $str, Types|null $types = null): Type
    {
        /**
         * @psalm-suppress InternalClass
         * @psalm-suppress InternalMethod
         */
        $node = TypeParser::parseString($str);
        if ($node instanceof SyntaxError) {
            throw $node;
        }
        $type = ($types ?? new Types())->resolve($node);
        if ($type instanceof TypeError) {
            throw $type;
        }
        return $type;
    }

    /**
     * @dataProvider equal
     */
    public function testEqual(string $a, string $b): void
    {
        $typeA = self::fromString($a);
        $typeB = self::fromString($b);

        self::assertTrue($typeA->equals($typeB));
        self::assertTrue($typeB->equals($typeA));
    }

    /**
     * @dataProvider notEqual
     */
    public function testNotEqual(string $a, string $b): void
    {
        $typeA = self::fromString($a);
        $typeB = self::fromString($b);

        self::assertFalse($typeA->equals($typeB));
        self::assertFalse($typeB->equals($typeA));
    }
}
