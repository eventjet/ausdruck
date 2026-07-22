<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Test\Unit\Parser;

use Eventjet\Ausdruck\Parser\TypeError;
use Eventjet\Ausdruck\Parser\TypeNode;
use Eventjet\Ausdruck\Parser\TypeParser;
use Eventjet\Ausdruck\Parser\Types;
use Eventjet\Ausdruck\Type;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
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

    #[DataProvider('resolveTypeErrorsCases')]
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

    /**
     * {@see Type::nestedFunc()} defers every variable it reaches to whichever signature encloses it -- correct for a
     * function type nested inside another one's own parameters or return type, but wrong for an alias's own target,
     * which nothing here encloses: text naming the alias has no `fn<...>` of its own to declare the hidden variable
     * with, so resolving a reference to it would otherwise crash deep inside {@see Type::genericFunc()} instead of
     * being rejected where the alias was registered.
     */
    public function testAnAliasBuiltThroughTheNestedDoorIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Mapper is declared as fn(T) -> bool, which reaches a type variable nothing captures -- every function '
                . 'type it reaches through Type::nestedFunc() needs its own binder instead, via Type::func() or '
                . 'Type::genericFunc()',
        );

        new Types(['Mapper' => Type::nestedFunc(Type::bool(), [Type::var('T')])]);
    }
}
