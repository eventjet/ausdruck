<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Test\Unit;

use Eventjet\Ausdruck\Parser\SyntaxError;
use Eventjet\Ausdruck\Parser\TypeError;
use Eventjet\Ausdruck\Parser\TypeParser;
use Eventjet\Ausdruck\Parser\Types;
use Eventjet\Ausdruck\Type;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function assert;
use function fopen;
use function sprintf;

final class TypeTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidValues(): iterable
    {
        yield 'resource' => [fopen('php://memory', 'r')];
    }

    /**
     * @return iterable<string, array{Type, mixed, string}>
     */
    public static function failingAssertCases(): iterable
    {
        yield 'Function is not callable' => [
            Type::func(Type::string()),
            'not a function',
            'Expected func(): string, got string',
        ];
        yield 'Struct: not an object' => [
            Type::struct(['name' => Type::string()]),
            'not an object',
            'Expected { name: string }, got string',
        ];
        yield 'Missing struct field' => [
            Type::struct(['name' => Type::string(), 'age' => Type::int()]),
            new class {
                public string $name = 'John Doe';
            },
            'Expected { name: string, age: int }, got { name: string }',
        ];
        yield 'Struct field has wrong type' => [
            Type::struct(['name' => Type::string()]),
            new class {
                public int $name = 42;
            },
            'Expected { name: string }, got { name: int }',
        ];
        $name = new class {
            public string $first = 'John';
        };
        yield 'Struct field has subtype' => [
            Type::struct(['name' => Type::struct(['first' => Type::string(), 'last' => Type::string()])]),
            new class ($name) {
                public function __construct(public object $name)
                {
                }
            },
            'Expected { name: { first: string, last: string } }, got { name: { first: string } }',
        ];
    }

    /**
     * @return iterable<string, array{Type, mixed}>
     */
    public static function successfulAssertCases(): iterable
    {
        yield 'Struct' => [
            Type::struct(['name' => Type::string()]),
            new class {
                public string $name = 'John Doe';
            },
        ];
        yield 'Struct is allowed to have additional fields' => [
            Type::struct(['name' => Type::string()]),
            new class {
                public string $name = 'John Doe';
                public int $age = 42;
            },
        ];
        $name = new class {
            public string $first = 'John';
            public string $last = 'Doe';
        };
        yield 'Struct field has supertype' => [
            Type::struct(['name' => Type::struct(['first' => Type::string()])]),
            new class ($name) {
                public function __construct(public readonly object $name)
                {
                }
            },
        ];
        yield 'Map and empty array' => [Type::mapOf(Type::string(), Type::int()), []];
        yield 'List and empty array' => [Type::listOf(Type::string()), []];
    }

    /**
     * @return iterable<string, array{mixed, Type}>
     */
    public static function fromValuesCases(): iterable
    {
        yield 'struct' => [
            new class {
                public string $name = 'John Doe';
                public int $age = 42;
            },
            Type::struct(['name' => Type::string(), 'age' => Type::int()]),
        ];
    }

    /**
     * @return iterable<string, array{Type | callable(): Type, Type | callable(): Type}>
     */
    public static function equalsCases(): iterable
    {
        yield 'Some<T> == T' => [
            static fn() => Type::some(Type::string()),
            Type::string(),
        ];
    }

    /**
     * @return iterable<string, array{Type, string}>
     */
    public static function toStringCases(): iterable
    {
        yield 'Struct' => [
            Type::struct(['name' => Type::string(), 'age' => Type::int()]),
            '{ name: string, age: int }',
        ];
    }

    /**
     * @return iterable<string, array{Type, Type}>
     */
    public static function notEqualsCases(): iterable
    {
        $cases = [
            ['{name: string}', '{name: string, age: int}'],
            ['{name: string}', '{name: int}'],
            ['{name: string}', '{firstName: string}'],
            ['Some<string>', 'Some<int>'],
            ['any', 'string'],
        ];
        foreach ($cases as [$a, $b]) {
            /**
             * @psalm-suppress InternalMethod
             * @psalm-suppress InternalClass
             */
            $nodeA = TypeParser::parseString($a);
            /**
             * @psalm-suppress InternalMethod
             * @psalm-suppress InternalClass
             */
            $nodeB = TypeParser::parseString($b);
            assert(!$nodeA instanceof SyntaxError);
            assert(!$nodeB instanceof SyntaxError);
            $types = new Types();
            $typeA = $types->resolve($nodeA);
            $typeB = $types->resolve($nodeB);
            assert(!$typeA instanceof TypeError);
            assert(!$typeB instanceof TypeError);
            yield sprintf('%s vs. %s', $a, $b) => [$typeA, $typeB];
        }
    }

    #[DataProvider('invalidValues')]
    public function testFromInvalidValue(mixed $value): void
    {
        $this->expectException(LogicException::class);

        Type::fromValue($value);
    }

    public function testAliasTypeEqualsAliasTarget(): void
    {
        $concrete = Type::listOf(Type::string());
        $alias = Type::alias('Foo', $concrete);

        self::assertTrue($alias->equals($concrete));
        self::assertTrue($concrete->equals($alias));
    }

    public function testDifferentAliasesForTheSameTypeAreEqual(): void
    {
        $concrete = Type::listOf(Type::string());
        $foo = Type::alias('Foo', $concrete);
        $bar = Type::alias('Bar', $concrete);

        self::assertTrue($foo->equals($bar));
        self::assertTrue($bar->equals($foo));
    }

    #[DataProvider('failingAssertCases')]
    public function testFailingAssert(Type $type, mixed $value, string $expectedMessage): void
    {
        $this->expectException(TypeError::class);
        $this->expectExceptionMessage($expectedMessage);

        $type->assert($value);
    }

    #[DataProvider('notEqualsCases')]
    public function testNotEquals(Type $a, Type $b): void
    {
        self::assertFalse($a->equals($b));
        self::assertFalse($b->equals($a));
    }

    #[DataProvider('successfulAssertCases')]
    public function testSuccessfulAssert(Type $type, mixed $value): void
    {
        $this->expectNotToPerformAssertions();

        $type->assert($value);
    }

    #[DataProvider('fromValuesCases')]
    public function testFromValue(mixed $value, Type $expected): void
    {
        $actual = Type::fromValue($value);

        self::assertTrue($actual->equals($expected));
    }

    /**
     * @param Type | callable(): Type $a
     * @param Type | callable(): Type $b
     */
    #[DataProvider('equalsCases')]
    public function testEquals(Type|callable $a, Type|callable $b): void
    {
        $a = $a instanceof Type ? $a : $a();
        $b = $b instanceof Type ? $b : $b();

        self::assertTrue($a->equals($b));
        self::assertTrue($b->equals($a));
    }

    #[DataProvider('toStringCases')]
    public function testToString(Type $type, string $expected): void
    {
        self::assertSame($expected, (string)$type);
    }
}
