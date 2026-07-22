<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Test\Unit;

use Eventjet\Ausdruck\AliasShape;
use Eventjet\Ausdruck\Get;
use Eventjet\Ausdruck\Parser\Declarations;
use Eventjet\Ausdruck\Parser\ExpressionParser;
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
     * bind() only sees through an alias on the actual side, so a variable under an alias on the signature side has to
     * be reachable too, or instantiating a signature built directly through this API silently drops it to `any`
     * instead of what the call actually decided. Built through the internal {@see AliasShape} directly rather than
     * the public {@see Type::alias()}: `Bag` standing for `list<T>` is exactly the target the public door now refuses,
     * since nothing would capture `T` if `Bag` were ever used standalone -- {@see Type::bind()}'s own walk doesn't get
     * to assume that was checked, though, so it still has to be correct for a shape like this one.
     */
    public function testVariableUnderAnAliasOnTheSignatureSideIsBound(): void
    {
        $bag = Type::of(new AliasShape('Bag', Type::listOf(Type::var('T'))));
        $signature = Type::func(Type::var('T'), [$bag])->asFunction();
        self::assertNotNull($signature);

        $instantiated = $signature->instantiateForCall(Type::listOf(Type::int()), []);

        self::assertTrue($instantiated->returnType->equals(Type::int()));
        self::assertTrue($instantiated->receiverType()?->isSubtypeOf(Type::listOf(Type::int())) ?? false);
    }

    /**
     * bind() canonicalizes both sides before asking whether either one is a variable, not just before comparing
     * names: an alias standing directly for a variable -- not merely for a container of one, see
     * {@see self::testVariableUnderAnAliasOnTheSignatureSideIsBound()} -- has to be seen through before that
     * question is asked, or the variable case is skipped on the raw, still-aliased type and the name comparison
     * that follows compares the variable's own name against the actual type's, which never match. Built through the
     * internal {@see AliasShape} directly, for the same reason
     * {@see self::testVariableUnderAnAliasOnTheSignatureSideIsBound()} does.
     */
    public function testVariableDirectlyBehindAnAliasIsBound(): void
    {
        $elem = Type::of(new AliasShape('Elem', Type::var('T')));
        $signature = Type::func(Type::var('T'), [Type::listOf($elem)])->asFunction();
        self::assertNotNull($signature);

        $instantiated = $signature->instantiateForCall(Type::listOf(Type::int()), []);

        self::assertTrue($instantiated->returnType->equals(Type::int()));
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
     * {@see Type::func()} never claims a binder of its own, so aliasing a function type that reaches a type
     * variable is what quantifies it -- the same promotion {@see Parser\Declarations} makes for a declared function
     * -- rather than leaving the variable free for nothing to capture.
     */
    public function testAliasingAFunctionTypeQuantifiesIt(): void
    {
        $alias = Type::alias('Mapper', Type::func(Type::bool(), [Type::var('T')]));

        self::assertSame(['T'], $alias->asFunction()?->binder());
        self::assertSame('Mapper', (string)$alias);
    }

    /**
     * The same free-variable check {@see Parser\Declarations::checkVariableIsSelfContained()} runs for a declared
     * variable's type applies to an alias target too, for anything that isn't a function type: aliasing doesn't
     * quantify a list, an `Option`, or a struct field, so a variable reaching through one of those is still nothing
     * captures it.
     */
    public function testAliasOfANonFunctionTypeReachingAFreeVariableIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Numbers is declared as list<T>, which reaches a type variable nothing captures -- aliasing only '
                . 'derives a binder for a function type, and this isn\'t one',
        );

        Type::alias('Numbers', Type::listOf(Type::var('T')));
    }

    /**
     * A variable used only in the return type -- nothing in the parameters reaches it -- is still part of the
     * derived binder: {@see Signature::quantified()} walks the return type too, not just the parameters. Nothing
     * decides it from a call, so {@see Signature::instantiateForCall()} leaves it as `any`, but it isn't unbound.
     */
    public function testQuantifiedDerivesAVariableUsedOnlyInTheReturnType(): void
    {
        $signature = Signature::quantified(Type::func(Type::var('T')));

        self::assertSame(['T'], $signature?->binder());
    }

    /**
     * A consumer's own generic higher-order function -- one whose parameter is itself a generic function type, the
     * way {@see \Eventjet\Ausdruck\BuiltinFunctions}' own `map` is -- builds that parameter through
     * {@see Type::func()} too, the same door as the outer signature: the inner function type's `T` and `U` are the
     * outer signature's own, not a binder of its own that shadows them, since {@see Type::func()} never claims one.
     * {@see Signature::quantified()} -- the same seam {@see Declarations} declares `myMap` through -- is what derives
     * the outer signature's binder, and a call through the declaration type-checks the same way a call to the
     * built-in `map` does, and the printed signature reads back to the same one.
     */
    public function testConsumerDeclaredGenericHigherOrderFunctionTypeChecksAndRoundTrips(): void
    {
        $myMap = Type::func(
            Type::listOf(Type::var('U')),
            [Type::listOf(Type::var('T')), Type::func(Type::var('U'), [Type::var('T')])],
        );
        $quantified = Signature::quantified($myMap);
        self::assertNotNull($quantified);
        self::assertSame('fn<T, U>(list<T>, fn(T) -> U) -> list<U>', (string)$quantified);

        /**
         * @psalm-suppress InternalMethod
         * @psalm-suppress InternalClass
         */
        $node = TypeParser::parseString((string)$quantified);
        self::assertNotInstanceOf(SyntaxError::class, $node);
        $reParsed = (new Types())->resolve($node);
        self::assertNotInstanceOf(TypeError::class, $reParsed);
        self::assertSame((string)$quantified, (string)$reParsed);

        $declarations = new Declarations(functions: ['myMap' => $myMap]);
        $expression = ExpressionParser::parse('nums:list<int>.myMap(|n| n:int > 2)', $declarations);

        self::assertSame('list<bool>', (string)$expression->getType());
    }

    /**
     * A name {@see TypeConstructor} already spells would print as, say, `fn<int>(int) -> int` -- indistinguishable
     * from the built-in type `int` once it's written syntax, and rejected as a type variable for exactly that reason
     * when a signature is parsed from a string. {@see Type::var()} rejects the same name for the same
     * `parse(str(t)) === t` reason, even though the PHP calls `Type::var('int')` and `Type::int()` are themselves
     * unambiguous.
     */
    public function testVarRejectsANameATypeConstructorAlreadySpells(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('int can\'t be a type variable: it is a type of its own');

        Type::var('int');
    }

    /**
     * `fn` isn't a {@see \Eventjet\Ausdruck\TypeConstructor} case -- a function type is its own node shape -- but
     * it's still the one bare word {@see TypeParser::parse()} always reads as introducing a function type, so a
     * variable named `fn` would print as a bare `fn` no parser could ever read back as a variable reference.
     */
    public function testVarRejectsFn(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('fn can\'t be a type variable: it is a type of its own');

        Type::var('fn');
    }

    /**
     * The same reservation {@see self::testVarRejectsANameATypeConstructorAlreadySpells()} pins for {@see Type::var()}
     * applies to {@see Type::alias()} too, worded for an alias rather than a variable.
     */
    public function testAliasRejectsANameATypeConstructorAlreadySpells(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('list can\'t be an alias: it is a type of its own');

        Type::alias('list', Type::int());
    }

    /**
     * `Struct` and `never` are deliberately not reserved -- see the `named-Struct-is-an-ordinary-name` and
     * `named-after-the-bottom-type` fixtures -- so {@see Type::var()} and {@see Type::alias()} still take them
     * without complaint.
     */
    public function testVarAndAliasAllowStructAndNever(): void
    {
        self::assertSame('Struct', (string)Type::var('Struct'));
        self::assertSame('never', (string)Type::var('never'));
        self::assertSame('Struct', (string)Type::alias('Struct', Type::int()));
        self::assertSame('never', (string)Type::alias('never', Type::int()));
    }

    /**
     * A function type nested inside another one's own parameters -- `doCall`'s own receiver parameter,
     * `fn(string) -> string` -- is built through {@see Type::func()} exactly like the outer one: there is only ever
     * this one door, so nesting one function type inside another is never anything a caller has to get right by
     * picking the right constructor.
     */
    public function testFuncAllowsNestingAFunctionTypeAsAParameter(): void
    {
        $doCall = Type::func(Type::string(), [Type::func(Type::string(), [Type::string()]), Type::string()]);

        self::assertSame('fn(fn(string) -> string, string) -> string', (string)$doCall);
    }

    /**
     * A polymorphic function type and a monomorphic one over a variable belonging to an enclosing scope are
     * different types, even though their return type and parameters read the same: `fn<T>(T) -> T`'s `T` is decided
     * fresh by every call, while `fn(T) -> T`'s `T` -- the way {@see Type::func()} always builds it, since it never
     * claims a binder of its own -- is one variable some enclosing signature owns. Aliasing derives a binder for a
     * function type ({@see Type::alias()}), so aliasing the polymorphic one is the plain-API way to get one to
     * compare against a bare, unquantified one. {@see Signature::hasOwnBinder()} is the fact that tells the two
     * apart, and {@see Type::isSubtypeOf()} has to read it, or they compare equal.
     */
    public function testAPolymorphicFunctionTypeIsNotEqualToAMonomorphicOneOverAFreeVariable(): void
    {
        $t = Type::var('T');
        $monomorphic = Type::func($t, [$t]);
        $polymorphic = Type::alias('F', $monomorphic);

        self::assertFalse($polymorphic->equals($monomorphic));
        self::assertFalse($monomorphic->equals($polymorphic));
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
