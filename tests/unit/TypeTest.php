<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Test\Unit;

use Eventjet\Ausdruck\Parser\TypeError;
use Eventjet\Ausdruck\Type;
use LogicException;
use PHPUnit\Framework\TestCase;

use function fopen;

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
            'Expected {name: string}, got string',
        ];
        yield 'Missing struct field' => [
            Type::struct(['name' => Type::string(), 'age' => Type::int()]),
            new class {
                public string $name = 'John Doe';
            },
            'Expected {name: string, age: int}, got {name: string}',
        ];
        yield 'Struct field has wrong type' => [
            Type::struct(['name' => Type::string()]),
            new class {
                public int $name = 42;
            },
            'Expected {name: string}, got {name: int}',
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
            'Expected {name: {first: string, last: string}}, got {name: {first: string}}',
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
     * @return iterable<string, array{Type, Type}>
     */
    public static function notEqualsCases(): iterable
    {
        yield 'Struct: one has more fields' => [
            Type::struct(['name' => Type::string()]),
            Type::struct(['name' => Type::string(), 'age' => Type::int()]),
        ];
        yield 'Struct: one has different field type' => [
            Type::struct(['name' => Type::string()]),
            Type::struct(['name' => Type::int()]),
        ];
        yield 'Struct: one has different field name' => [
            Type::struct(['name' => Type::string()]),
            Type::struct(['firstName' => Type::string()]),
        ];
    }

    /**
     * @return iterable<string, array{Type, string}>
     */
    public static function toStringCases(): iterable
    {
        yield 'Struct' => [
            Type::struct(['name' => Type::string(), 'age' => Type::int()]),
            '{name: string, age: int}',
        ];
    }

    /**
     * @dataProvider invalidValues
     */
    public function testFromInvalidValue(mixed $value): void
    {
        $this->expectException(LogicException::class);

        Type::fromValue($value);
    }

    public function testAliasTypeEqualsAliasTarget(): void
    {
        $concrete = Type::listOf(Type::string());
        $alias = Type::alias('Foo', $concrete);

        /** @psalm-suppress RedundantCondition */
        self::assertTrue($alias->equals($concrete));
        /** @psalm-suppress RedundantCondition */
        self::assertTrue($concrete->equals($alias));
    }

    public function testDifferentAliasesForTheSameTypeAreEqual(): void
    {
        $concrete = Type::listOf(Type::string());
        $foo = Type::alias('Foo', $concrete);
        $bar = Type::alias('Bar', $concrete);

        /** @psalm-suppress RedundantCondition */
        self::assertTrue($foo->equals($bar));
        /** @psalm-suppress RedundantCondition */
        self::assertTrue($bar->equals($foo));
    }

    /**
     * @dataProvider failingAssertCases
     */
    public function testFailingAssert(Type $type, mixed $value, string $expectedMessage): void
    {
        $this->expectException(TypeError::class);
        $this->expectExceptionMessage($expectedMessage);

        $type->assert($value);
    }

    /**
     * @dataProvider successfulAssertCases
     */
    public function testSuccessfulAssert(Type $type, mixed $value): void
    {
        $this->expectNotToPerformAssertions();

        $type->assert($value);
    }

    /**
     * @dataProvider fromValuesCases
     */
    public function testFromValue(mixed $value, Type $expected): void
    {
        $actual = Type::fromValue($value);

        /** @psalm-suppress RedundantCondition */
        self::assertTrue($actual->equals($expected));
    }

    /**
     * @dataProvider notEqualsCases
     */
    public function testNotEquals(Type $a, Type $b): void
    {
        self::assertFalse($a->equals($b));
        self::assertFalse($b->equals($a));
    }

    /**
     * @dataProvider toStringCases
     */
    public function testToString(Type $type, string $expected): void
    {
        self::assertSame($expected, (string)$type);
    }
}
