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
     * @return iterable<string, array{Type<mixed>, mixed, string}>
     */
    public static function failingAssertCases(): iterable
    {
        yield 'Function is not callable' => [
            Type::func(Type::string()),
            'not a function',
            'Expected callable, got string',
        ];
        yield 'Struct: not an object' => [
            Type::struct(['name' => Type::string()]),
            'not an object',
            'Expected object, got string',
        ];
        yield 'Missing struct field' => [
            Type::struct(['name' => Type::string(), 'age' => Type::int()]),
            new class {
                public string $name = 'John Doe';
            },
            'Expected object with property age',
        ];
        yield 'Struct field has wrong type' => [
            Type::struct(['name' => Type::string()]),
            new class {
                public int $name = 42;
            },
            'Expected string, got int',
        ];
    }

    /**
     * @return iterable<string, array{Type<mixed>, mixed}>
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
    }

    /**
     * @return iterable<string, array{mixed, Type<mixed>}>
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
     * @return iterable<string, array{Type<mixed>, Type<mixed>}>
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
     * @return iterable<string, array{Type<mixed>, string}>
     */
    public static function toStringCases(): iterable
    {
        yield 'Struct' => [
            Type::struct(['name' => Type::string(), 'age' => Type::int()]),
            '{name: string, age: int}',
        ];
    }

    /**
     * We might want to accept empty arrays in the future, but we would probably want a never type for that.
     */
    public function testFromValueFailsWhenGivenAnEmptyArray(): void
    {
        $this->expectException(LogicException::class);

        Type::fromValue([]);
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
     * @param Type<mixed> $type
     * @dataProvider failingAssertCases
     */
    public function testFailingAssert(Type $type, mixed $value, string $expectedMessage): void
    {
        $this->expectException(TypeError::class);
        $this->expectExceptionMessage($expectedMessage);

        $type->assert($value);
    }

    /**
     * @param Type<mixed> $type
     * @dataProvider successfulAssertCases
     */
    public function testSuccessfulAssert(Type $type, mixed $value): void
    {
        $this->expectNotToPerformAssertions();

        $type->assert($value);
    }

    /**
     * @param Type<mixed> $expected
     * @dataProvider fromValuesCases
     */
    public function testFromValue(mixed $value, Type $expected): void
    {
        $actual = Type::fromValue($value);

        /** @psalm-suppress RedundantCondition */
        self::assertTrue($actual->equals($expected));
    }

    /**
     * @param Type<mixed> $a
     * @param Type<mixed> $b
     * @dataProvider notEqualsCases
     */
    public function testNotEquals(Type $a, Type $b): void
    {
        /**
         * @psalm-suppress RedundantCondition
         * @phpstan-ignore-next-line staticMethod.impossibleType
         */
        self::assertFalse($a->equals($b));
        /**
         * @psalm-suppress RedundantCondition
         * @phpstan-ignore-next-line staticMethod.impossibleType
         */
        self::assertFalse($b->equals($a));
    }

    /**
     * @param Type<mixed> $type
     * @dataProvider toStringCases
     */
    public function testToString(Type $type, string $expected): void
    {
        self::assertSame($expected, (string)$type);
    }
}
