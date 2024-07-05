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
        yield 'Struct is an int' => [Type::struct(['foo' => Type::string()]), 42, 'Expected object, got int'];
        yield 'Struct is an array' => [
            Type::struct(['foo' => Type::string()]),
            ['foo' => 'bar'],
            'Expected object, got array',
        ];
        yield 'Struct is missing a field' => [
            Type::struct(['foo' => Type::string()]),
            (object)[],
            'Missing property "foo"',
        ];
        yield 'Struct: wrong field type' => [
            Type::struct(['foo' => Type::string()]),
            (object)['foo' => 42],
            'Property "foo" must be of type string, got int',
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
        $concrete = Type::listOf(Type::bool());
        $alias = Type::alias('Foo', $concrete);

        /** @psalm-suppress RedundantCondition */
        self::assertTrue($alias->equals($concrete));
        /** @psalm-suppress RedundantCondition */
        self::assertTrue($concrete->equals($alias));
    }

    public function testDifferentAliasesForTheSameTypeAreEqual(): void
    {
        $concrete = Type::listOf(Type::float());
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
}
