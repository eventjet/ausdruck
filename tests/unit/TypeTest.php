<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Test\Unit;

use Eventjet\Ausdruck\Get;
use Eventjet\Ausdruck\Parser\SyntaxError;
use Eventjet\Ausdruck\Parser\TypeError;
use Eventjet\Ausdruck\Parser\TypeParser;
use Eventjet\Ausdruck\Parser\Types;
use Eventjet\Ausdruck\Signature;
use Eventjet\Ausdruck\Type;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function assert;
use function fopen;
use function json_decode;
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
            'Expected fn() -> string, got string',
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
        yield 'Numeric property names are not struct fields' => [
            json_decode('{"1": "one", "name": "John Doe"}'),
            Type::struct(['name' => Type::string()]),
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

    #[DataProvider('invalidValues')]
    public function testFromInvalidValue(mixed $value): void
    {
        $this->expectException(LogicException::class);

        Type::fromValue($value);
    }

    /**
     * A name a type constructor already spells is a type, not a placeholder for one—the same rule {@see Types}
     * enforces where a signature is written as a type string, enforced here too so a signature built directly through
     * this API can't spell one the parser would reject.
     */
    public function testVariableNamedAfterATypeConstructorIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('int can\'t be a type variable: it is a type of its own');

        Type::var('int');
    }

    /**
     * The same reservation {@see Type::var()} enforces, for the same reason plus one of its own: a few checks --
     * {@see self::toString()}'s `Struct` and `fn` cases -- read an alias's own name directly, without seeing through
     * it first, to decide whether $this *is* a function type or a struct. A name a type constructor already spells
     * collides with exactly that check, so `Type::alias('Struct', ...)` used to print as `{}` and
     * `Type::alias('fn', ...)` crashed outright; this closes both at the door instead.
     */
    public function testAliasNamedAfterATypeConstructorIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Struct can\'t be an alias: it is a type of its own');

        Type::alias('Struct', Type::listOf(Type::int()));
    }

    /**
     * bind() only sees through an alias on the actual side, so a variable under an alias on the signature side has to
     * be reachable too, or instantiating a signature built directly through this API silently drops it to `any`
     * instead of what the call actually decided.
     */
    public function testVariableUnderAnAliasOnTheSignatureSideIsBound(): void
    {
        $signature = Type::func(Type::var('T'), [Type::alias('Bag', Type::listOf(Type::var('T')))], ['T'])->asFunction();
        self::assertNotNull($signature);

        $instantiated = $signature->instantiateForCall(Type::listOf(Type::int()), []);

        self::assertTrue($instantiated->returnType->equals(Type::int()));
        self::assertTrue($instantiated->receiverType()?->isSubtypeOf(Type::listOf(Type::int())) ?? false);
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

    /**
     * An alias is a name for one complete type, so it prints as that name and nothing else. Printing the arguments of
     * the type it stands for would spell a type the parser rejects: `Bag<string>` reads as arguments applied to Bag,
     * and an alias takes none.
     */
    public function testAliasOfAParameterizedTypePrintsAsItsNameAlone(): void
    {
        $alias = Type::alias('Bag', Type::listOf(Type::string()));

        self::assertSame('Bag', (string)$alias);
    }

    /**
     * Aliasing hides the layout a function type keeps its return and parameter types in, so the accessors have to look
     * through the alias the same way subtyping and field lookup do.
     */
    public function testAliasOfAFunctionTypeKeepsItsSignatureReadable(): void
    {
        $alias = Type::alias('Callback', Type::func(Type::string(), [Type::int(), Type::bool()]));

        $signature = $alias->asFunction();

        self::assertNotNull($signature);
        self::assertTrue($signature->returnType->equals(Type::string()));
        self::assertTrue($signature->receiverType()?->equals(Type::int()) ?? false);
        self::assertEquals([Type::bool()], $signature->argumentTypes());
    }

    /**
     * {@see Type::func()} can't reject this itself: a variable with no binder of its own -- the `T` in a lambda
     * parameter's `fn(T) -> bool`, say -- legitimately defers to whichever binder ends up enclosing it, and while
     * {@see Type::func()} is still building that enclosing signature, "ends up" hasn't happened yet.
     * {@see Type::asFunction()} is the first point it's known for certain that nothing ever will, which is why the
     * check lives there instead.
     */
    public function testAsFunctionRejectsAVariableItsOwnBinderDoesntDeclare(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('T isn\'t declared by this function type\'s own binder, so nothing quantifies it');

        Type::func(Type::var('T'), [Type::listOf(Type::var('T'))])->asFunction();
    }

    /**
     * The same rejection, for a variable one binder over from the one that would have to declare it: a signature
     * declaring `U` doesn't make `T` -- used nowhere else -- any less unbound.
     */
    public function testAsFunctionRejectsAVariableOnlyAWiderBinderDeclares(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('T isn\'t declared by this function type\'s own binder, so nothing quantifies it');

        Type::func(Type::var('T'), [Type::var('U')], ['U'])->asFunction();
    }

    /**
     * The parser rejects this same nesting when a signature is written as a type string -- see the
     * generics/type-variable fixtures -- because {@see Signature::instantiateForCall()}'s substitution can't tell
     * the inner binder's own variables from the outer signature's: instantiating the outer call would replace T
     * inside the nested fn<T> with whatever the outer call decided, corrupting a signature that was never meant to
     * be touched by that call at all. Built directly through this API, there's no parser to catch it first, so
     * Type::func() enforces the same rule itself.
     */
    public function testFunctionTypeRejectsAParameterWithANestedBinderOfItsOwn(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'A function type nested inside another one can\'t bind type variables of its own: a variable is '
                . 'quantified once, by whichever function type encloses it',
        );

        Type::func(Type::int(), [Type::listOf(Type::func(Type::var('T'), [Type::var('T')], ['T']))]);
    }

    /**
     * The same rejection as {@see self::testFunctionTypeRejectsAParameterWithANestedBinderOfItsOwn()}, for the
     * return type instead of a parameter, and with the nested binder immediately there rather than behind a list --
     * the shape that, left unrejected, {@see Signature::instantiateForCall()} would silently collapse to
     * `fn(any) -> any` instead of leaving the inner fn<T> generic.
     */
    public function testFunctionTypeRejectsAReturnTypeWithANestedBinderOfItsOwn(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'A function type nested inside another one can\'t bind type variables of its own: a variable is '
                . 'quantified once, by whichever function type encloses it',
        );

        Type::func(Type::func(Type::var('T'), [Type::var('T')], ['T']), [Type::var('U')], ['U']);
    }

    /**
     * A list's element type is compared by asking what kind of type the left side is, and an alias only answers that
     * once it's been seen through. Asking the alias directly makes it none of the kinds that carry a check, so a list
     * of one thing would pass as a list of another.
     */
    public function testAliasOfAListComparesItsElementType(): void
    {
        $ints = Type::alias('Ints', Type::listOf(Type::int()));

        self::assertFalse($ints->isSubtypeOf(Type::listOf(Type::string())));
        self::assertFalse($ints->isSubtypeOf(Type::alias('Strings', Type::listOf(Type::string()))));
        self::assertTrue($ints->isSubtypeOf(Type::listOf(Type::int())));
    }

    /**
     * The same for a struct: seeing through the alias is what leaves a struct to compare field by field.
     */
    public function testAliasOfAStructComparesItsFields(): void
    {
        $alias = Type::alias('Person', Type::struct(['name' => Type::string()]));

        self::assertFalse($alias->isSubtypeOf(Type::struct(['name' => Type::int()])));
        self::assertFalse($alias->isSubtypeOf(Type::struct(['age' => Type::int()])));
        self::assertTrue($alias->isSubtypeOf(Type::struct(['name' => Type::string()])));
    }

    /**
     * isStruct() and getFieldType() already see through an alias to answer this; isOption() has to as well, or
     * {@see Get::evaluate()}'s null check -- which reads isOption() directly rather than going through
     * isSubtypeOf() -- rejects a missing variable declared with an alias for an Option the same way it would reject
     * one that's actually required.
     */
    public function testAliasOfAnOptionIsRecognizedAsOne(): void
    {
        $alias = Type::alias('Maybe', Type::option(Type::string()));

        self::assertTrue($alias->isOption());
    }

    #[DataProvider('failingAssertCases')]
    public function testFailingAssert(Type $type, mixed $value, string $expectedMessage): void
    {
        $this->expectException(TypeError::class);
        $this->expectExceptionMessage($expectedMessage);

        $type->assert($value);
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

    #[DataProvider('notEqualsCases')]
    public function testNotEquals(Type $a, Type $b): void
    {
        self::assertFalse($a->equals($b));
        self::assertFalse($b->equals($a));
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
