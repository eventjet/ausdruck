<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Test\Unit\Parser;

use Eventjet\Ausdruck\Parser\TypeError;
use Eventjet\Ausdruck\Parser\TypeNode;
use Eventjet\Ausdruck\Parser\TypeParser;
use Eventjet\Ausdruck\Parser\Types;
use PHPUnit\Framework\TestCase;

use function assert;

final class TypesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function resolveTypeErrorsCases(): iterable
    {
        yield 'Unknown function return type' => ['fn() -> Nope', 'Unknown type Nope'];
        yield 'Unknown function parameter' => ['fn(Nope) -> string', 'Unknown type Nope'];
    }

    /**
     * @dataProvider resolveTypeErrorsCases
     */
    public function testResolveTypeErrors(string $type, string $expectedMessage): void
    {
        /**
         * @psalm-suppress InternalClass
         * @psalm-suppress InternalMethod
         */
        $node = TypeParser::parseString($type);
        assert($node instanceof TypeNode);
        $error = (new Types())->resolve($node);

        self::assertInstanceOf(TypeError::class, $error);
        self::assertSame($expectedMessage, $error->getMessage());
    }
}
