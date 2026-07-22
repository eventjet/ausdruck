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
     * {@see Type::func()} never claims a binder of its own, so an alias's own target -- built the same way any
     * other function type is -- is quantified by {@see Type::alias()} itself, the way {@see self::__construct()}
     * pre-wraps every entry through it: a bare, unquantified `fn(T) -> bool` reaching a variable nothing else
     * captures is accepted here instead of being rejected, the way anything that isn't a function type still is.
     */
    public function testAnAliasedFunctionTypeIsQuantified(): void
    {
        $types = new Types(['Mapper' => Type::func(Type::bool(), [Type::var('T')])]);

        /**
         * @psalm-suppress InternalClass
         * @psalm-suppress InternalMethod
         */
        $node = TypeParser::parseString('Mapper');
        assert($node instanceof TypeNode);
        $resolved = $types->resolve($node);

        self::assertNotInstanceOf(TypeError::class, $resolved);
        self::assertSame(['T'], $resolved->asFunction()?->binder());
    }

    /**
     * The same free-variable check {@see Type::alias()} runs for anything that isn't a function type: aliasing
     * doesn't quantify a list, so a variable reaching through one is still nothing captures it -- caught here, when
     * the alias is registered, rather than crashing deep inside {@see Signature::quantified()} once a reference to
     * it is resolved.
     */
    public function testAnAliasOfANonFunctionTypeReachingAFreeVariableIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Numbers is declared as list<T>, which reaches a type variable nothing captures -- aliasing only '
                . 'derives a binder for a function type, and this isn\'t one',
        );

        new Types(['Numbers' => Type::listOf(Type::var('T'))]);
    }
}
