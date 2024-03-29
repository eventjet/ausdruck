<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Test\Unit;

use Eventjet\Ausdruck\Parser\TypeError;
use Eventjet\Ausdruck\Test\Unit\Fixtures\SomeObject;
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

    public function testFromValueWithObjectEqualsFromObject(): void
    {
        $fromValue = Type::fromValue(new SomeObject());
        $object = Type::object(SomeObject::class);

        /** @psalm-suppress RedundantCondition */
        self::assertTrue($fromValue->equals($object));
        /** @psalm-suppress RedundantCondition */
        self::assertTrue($object->equals($fromValue));
    }

    public function testAliasTypeEqualsAliasTarget(): void
    {
        $concrete = Type::object(SomeObject::class);
        $alias = Type::alias('Foo', $concrete);

        /** @psalm-suppress RedundantCondition */
        self::assertTrue($alias->equals($concrete));
        /** @psalm-suppress RedundantCondition */
        self::assertTrue($concrete->equals($alias));
    }

    public function testDifferentAliasesForTheSameTypeAreEqual(): void
    {
        $concrete = Type::object(SomeObject::class);
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
