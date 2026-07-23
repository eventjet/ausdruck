<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Test\Unit;

use Eventjet\Ausdruck\AliasShape;
use Eventjet\Ausdruck\ApplicationShape;
use Eventjet\Ausdruck\ComparableShape;
use Eventjet\Ausdruck\Get;
use Eventjet\Ausdruck\Parser\Declarations;
use Eventjet\Ausdruck\Parser\ExpressionParser;
use Eventjet\Ausdruck\Parser\SyntaxError;
use Eventjet\Ausdruck\Parser\TypeError;
use Eventjet\Ausdruck\Parser\Types;
use Eventjet\Ausdruck\Signature;
use Eventjet\Ausdruck\StructShape;
use Eventjet\Ausdruck\Type;
use Eventjet\Ausdruck\VariableShape;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use function assert;
use function fopen;
use function json_decode;
use function sprintf;

final class TypeTest extends TestCase
{
    use ParsesTypeSyntax;

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
            $nodeA = self::parseTypeString($a);
            $nodeB = self::parseTypeString($b);
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
     * variable is what quantifies it -- the same promotion {@see Declarations} makes for a declared function
     * -- rather than leaving the variable free for nothing to capture.
     */
    public function testAliasingAFunctionTypeQuantifiesIt(): void
    {
        $alias = Type::alias('Mapper', Type::func(Type::bool(), [Type::var('T')]));

        self::assertSame(['T'], $alias->asFunction()?->binder());
        self::assertSame('Mapper', (string)$alias);
    }

    /**
     * The same free-variable check {@see Declarations::checkVariableIsSelfContained()} runs for a declared
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
     * A fixed parameter that happens to itself already be a generic signature -- reached through an alias, the same
     * way {@see self::testAliasingAFunctionTypeQuantifiesIt()} builds Mapper -- lends none of its own binder to a
     * signature that merely takes it as a parameter: Mapper's own `U` is quantified by Mapper itself
     * ({@see Signature::hasOwnBinder()}), not by whatever else Mapper is found inside -- the own-binder guard in
     * {@see Signature::collectVariables()} is what keeps {@see Signature::freeVariables()} from seeing it.
     * Without that guard this signature would derive a spurious `['U']` binder and print as `fn<U>(Mapper) -> int`
     * instead of `fn(Mapper) -> int` -- the same corruption
     * a-fixed-parameter-that-happens-to-be-generic-isnt-a-generic-parameter.txt pins for a written `fn<...>` resolved
     * through the parser ({@see \Eventjet\Ausdruck\Parser\TypeResolution::resolveSignature()}); this is the same rule
     * reached through the PHP-builder door, {@see Signature::quantified()}, instead.
     */
    public function testQuantifiedDoesNotBorrowABinderFromAFixedParameterThatIsAlreadyGeneric(): void
    {
        $mapper = Type::alias('Mapper', Type::func(Type::var('U'), [Type::var('U')]));

        $quantified = Signature::quantified(Type::func(Type::int(), [$mapper]));

        self::assertNotNull($quantified);
        self::assertSame([], $quantified->binder());
        self::assertSame('fn(Mapper) -> int', (string)$quantified);
    }

    /**
     * The same guard, but with two variables of the enclosing signature's own already found by the time it's
     * reached: {@see Signature::collectVariables()} answers $found back exactly as given, not a
     * truncated copy of it -- `A` and `B`, found walking the parameters before Mapper's own position, both survive.
     */
    public function testQuantifiedKeepsEarlierVariablesWhenALaterParameterIsAlreadyGeneric(): void
    {
        $mapper = Type::alias('Mapper', Type::func(Type::var('U'), [Type::var('U')]));

        $quantified = Signature::quantified(Type::func(Type::int(), [Type::var('A'), Type::var('B'), $mapper]));

        self::assertNotNull($quantified);
        self::assertSame(['A', 'B'], $quantified->binder());
    }

    /**
     * The builder-door counterpart to generics/type-variable/reusing-an-enclosing-binders-name, which rejects the
     * same shadowing in a written `fn<...>`: an enclosing signature may not derive for itself a binder name that a
     * nested, already-quantified generic function type inside it already declares. Here the derived outer binder
     * would be `U` -- the second parameter and the return type are a bare `U` nothing above them binds -- while the
     * first parameter, `fn<U>(U) -> U`, is a self-contained signature that binds its own `U`
     * ({@see Signature::hasOwnBinder()}). Printed, the two would collide as `fn<U>(fn<U>(U) -> U, U) -> U`, which the
     * parser rejects as a redeclared variable, so {@see Signature::over()} rejects deriving it in the first place
     * rather than mint a value that violates the `parse(str($type)) === $type` invariant. A distinct name for either
     * side has no such conflict -- see {@see self::testBindKeepsEarlierBindingsWhenALaterParameterIsAlreadyGeneric()},
     * whose enclosing binder is `A`, `B`.
     */
    public function testADerivedBinderThatWouldShadowANestedGenericSignatureIsRejected(): void
    {
        $mapperSignature = Signature::quantified(Type::func(Type::var('U'), [Type::var('U')]));
        self::assertNotNull($mapperSignature);
        $mapper = $mapperSignature->toType();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'The binder derived for fn<U>(fn<U>(U) -> U, U) -> U would introduce U, which a nested function type '
                . 'already declares -- the inner one would shadow the outer variable, so the type could never be read '
                . 'back the way it prints',
        );

        Signature::quantified(Type::func(Type::var('U'), [$mapper, Type::var('U')]));
    }

    /**
     * The shadowing check {@see self::testADerivedBinderThatWouldShadowANestedGenericSignatureIsRejected()} pins holds
     * however deep, and through whatever kind of type, the colliding binder name is buried:
     * {@see Signature::over()} gathers every name a nested `fn<...>` declares -- via {@see Type::collectBinderNames()}
     * across each shape -- before comparing them against the binder it derives, and here the collision is on `U`,
     * which is found only after `T` is already gathered and then carried, untouched, through a plain variable, a
     * `list<...>`, a struct field, and an alias in turn. If any of those shapes dropped a name it was merely passing
     * along, or the check stopped at the first binder name that happened not to collide (`A` here, derived before
     * `U`), the `U` collision would go unseen and the signature would be built rather than rejected.
     */
    public function testADerivedBinderShadowingANestedSignatureIsRejectedWhateverElseTheBodyThreadsThrough(): void
    {
        $bindsT = Signature::quantified(Type::func(Type::var('T'), [Type::var('T')]))?->toType();
        $bindsU = Signature::quantified(Type::func(Type::var('U'), [Type::var('U')]))?->toType();
        self::assertInstanceOf(Type::class, $bindsT);
        self::assertInstanceOf(Type::class, $bindsU);
        $mapper = Type::alias('Mapper', Type::func(Type::var('W'), [Type::var('W')]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/would introduce U, which a nested function type already declares/');

        Signature::quantified(Type::func(
            Type::var('U'),
            [$bindsT, $bindsU, Type::var('A'), Type::listOf(Type::int()), Type::struct(['x' => Type::int()]), $mapper],
        ));
    }

    /**
     * The same guard, but with two variables of the enclosing signature's own already bound by the time it's
     * reached: {@see Signature::bind()} answers $bindings back exactly as given, not a truncated
     * copy of it -- `A` and `B`, bound from the receiver and the first argument before Mapper's own position, both
     * survive into the substituted return type.
     */
    public function testBindKeepsEarlierBindingsWhenALaterParameterIsAlreadyGeneric(): void
    {
        $mapperSignature = Signature::quantified(Type::func(Type::var('U'), [Type::var('U')]));
        self::assertNotNull($mapperSignature);
        $mapper = $mapperSignature->toType();

        $outer = Signature::quantified(Type::func(
            Type::struct(['a' => Type::var('A'), 'b' => Type::var('B')]),
            [Type::var('A'), Type::var('B'), $mapper],
        ));
        self::assertNotNull($outer);

        $instantiated = $outer->instantiateForCall(
            Type::int(),
            [Type::string(), Type::func(Type::string(), [Type::string()])],
        );

        self::assertSame('{ a: int, b: string }', (string)$instantiated->returnType);
    }

    /**
     * {@see Signature::bind()} skips a parameter position whose actual type is `any` -- every
     * {@see Lambda} parameter -- without abandoning the walk: a later, real parameter still decides whatever it
     * faces. Two parameters in one nested function type, the first `any` and the second not, is what tells apart
     * skipping this one position from stopping the walk there -- a signature with only one bindable parameter, or
     * one where the `any` position comes last, reads the same either way.
     */
    public function testBindSkipsAnAnyParameterWithoutAbandoningTheWalk(): void
    {
        $outer = Signature::quantified(
            Type::func(Type::var('U'), [Type::func(Type::bool(), [Type::var('T'), Type::var('U')])]),
        );
        self::assertNotNull($outer);

        $instantiated = $outer->instantiateForCall(Type::func(Type::bool(), [Type::any(), Type::string()]), []);

        self::assertSame('string', (string)$instantiated->returnType);
    }

    /**
     * A shape mismatch -- here, `list<C>` faced with a `map` at a call site that's already wrong in some other way,
     * before {@see Expr::call()}'s own checks get to say so -- teaches {@see ApplicationShape::bind()}
     * nothing, but it doesn't undo what earlier parameters, walked first, already taught: `A` and `B` are decided by
     * the receiver and the first argument before the second argument's mismatch is ever reached.
     */
    public function testBindStopsLearningAtAShapeMismatchWithoutLosingEarlierBindings(): void
    {
        $outer = Signature::quantified(Type::func(
            Type::struct(['a' => Type::var('A'), 'b' => Type::var('B')]),
            [Type::var('A'), Type::var('B'), Type::listOf(Type::var('C'))],
        ));
        self::assertNotNull($outer);

        $instantiated = $outer->instantiateForCall(
            Type::int(),
            [Type::string(), Type::mapOf(Type::int(), Type::string())],
        );

        self::assertSame('{ a: int, b: string }', (string)$instantiated->returnType);
    }

    /**
     * A struct can be written with fewer fields than the value actually reaching it has --
     * {@see StructShape::bind()} learns only from the fields the two have in common; field types
     * the declared side doesn't mention are neither read nor allowed to decide a variable declared elsewhere.
     */
    public function testBindLearnsOnlyFromTheStructFieldsBothSidesHave(): void
    {
        $outer = Signature::quantified(Type::func(
            Type::struct(['name' => Type::var('T'), 'shoeSize' => Type::var('U')]),
            [Type::struct(['name' => Type::var('T'), 'shoeSize' => Type::var('U')])],
        ));
        self::assertNotNull($outer);

        $instantiated = $outer->instantiateForCall(
            Type::struct(['name' => Type::string(), 'shoeSize' => Type::int(), 'age' => Type::int()]),
            [],
        );

        self::assertSame('{ name: string, shoeSize: int }', (string)$instantiated->returnType);
    }

    /**
     * A signature with no binder of its own reaches no variable a call could decide -- see
     * {@see Signature::hasOwnBinder()} -- so instantiating it for a call hands back the very instance it started
     * from, not an equal one rebuilt from a walk that could only ever bind nothing and substitute nothing. Every
     * monomorphic call, like `substr`'s, takes this door, and the identity is what says the walk was skipped rather
     * than run to no effect.
     */
    public function testInstantiatingAMonomorphicSignatureForACallHandsBackTheSameInstance(): void
    {
        $monomorphic = Signature::quantified(Type::func(Type::string(), [Type::string(), Type::int()]));
        self::assertNotNull($monomorphic);

        self::assertSame($monomorphic, $monomorphic->instantiateForCall(Type::string(), [Type::int()]));
    }

    /**
     * Substituting into a self-contained generic signature leaves it untouched: its own binder is what quantifies its
     * variables, so an enclosing substitution -- even one that happens to carry a binding for the very name `X` the
     * signature quantifies -- has nothing to rewrite inside it. Without that opacity the binder would be dropped and
     * `X` replaced wholesale, turning `fn<X>(X) -> X` into `fn(int) -> int`.
     */
    public function testSubstitutingIntoASelfContainedGenericSignatureLeavesItUntouched(): void
    {
        $generic = Signature::quantified(Type::func(Type::var('X'), [Type::var('X')]));
        self::assertNotNull($generic);

        self::assertSame('fn<X>(X) -> X', (string)$generic->substitute(['X' => Type::int()]));
    }

    /**
     * The bind() counterpart to {@see self::testSubstitutingIntoASelfContainedGenericSignatureLeavesItUntouched()}: a
     * self-contained generic signature learns nothing from whatever function type faces it, since binding into it
     * would decide the variables its own binder already owns. Faced with `fn(string) -> int`, `fn<X>(X) -> X` binds
     * `X` to neither -- the bindings come back exactly as empty as they went in, rather than picking up `X => string`
     * from a walk that treated the opaque signature as one to learn from.
     */
    public function testASelfContainedGenericSignatureLearnsNothingFromWhatFacesIt(): void
    {
        $generic = Signature::quantified(Type::func(Type::var('X'), [Type::var('X')]));
        self::assertNotNull($generic);

        self::assertSame([], $generic->bind(Type::func(Type::int(), [Type::string()]), []));
    }

    /**
     * A signature-typed parameter faced with an actual that isn't a function type at all learns nothing from it --
     * {@see Signature::bind()} answers back the bindings it was given, whole. `A` and `B`, decided by the receiver and
     * the first argument before the third parameter's `fn(int) -> bool` meets a plain `int`, both survive into the
     * substituted return type; a truncated copy keeping only the first would leave `B` undecided and print `any` in
     * its place.
     */
    public function testASignatureParameterFacingANonFunctionActualKeepsEveryEarlierBinding(): void
    {
        $outer = Signature::quantified(Type::func(
            Type::struct(['a' => Type::var('A'), 'b' => Type::var('B')]),
            [Type::var('A'), Type::var('B'), Type::func(Type::bool(), [Type::int()])],
        ));
        self::assertNotNull($outer);

        $instantiated = $outer->instantiateForCall(Type::int(), [Type::string(), Type::int()]);

        self::assertSame('{ a: int, b: string }', (string)$instantiated->returnType);
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

        $node = self::parseTypeString((string)$quantified);
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
     * it's still the one bare word {@see \Eventjet\Ausdruck\Parser\TypeParser::parse()} always reads as introducing a
     * function type, so a variable named `fn` would print as a bare `fn` no parser could ever read back as a variable
     * reference.
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

    #[DataProvider('toStringCases')]
    public function testToString(Type $type, string $expected): void
    {
        self::assertSame($expected, (string)$type);
    }

    /**
     * The invariant {@see AliasShape}'s own docblock states -- an alias target can never reach a free variable, so
     * {@see AliasShape::collectVariables()} and {@see AliasShape::substitute()} can only ever answer identity --
     * pinned directly against the shape itself, rather than only against {@see Type::alias()}'s guard: built here
     * with a target that *does* reach one, bypassing that guard the way weakening it would, both answer for real
     * instead of silently staying identity, which is what would make the invariant's breakage visible.
     */
    public function testAliasShapeCollectVariablesAndSubstituteAreRealDelegationsNotAnIdentitySpecialCase(): void
    {
        $alias = new AliasShape('Foo', Type::var('T'));

        self::assertSame(['T' => true], $alias->collectVariables([]));

        $substituted = $alias->substitute(['T' => Type::string()]);

        self::assertTrue($substituted->isSubtypeOf(Type::string()));
        self::assertFalse($substituted->isSubtypeOf(Type::int()));
    }

    /**
     * An {@see AliasShape} is never reached by {@see Type::isSubtypeOf()} or {@see Type::bind()} -- both see through
     * every alias before ever comparing shapes -- so it doesn't implement {@see ComparableShape} at all, unlike every
     * other {@see TypeShape}: the invariant is enforced by the type system rather than by a throw for a call that
     * could never happen. Asked through {@see ReflectionClass::implementsInterface()} rather than
     * `assertInstanceOf()`: every one of these is already statically known to answer the same way, which is exactly
     * the point, but that also makes `assertInstanceOf()` itself flagged as redundant by static analysis --
     * reflection is what keeps the assertion runtime rather than compile-time.
     */
    public function testOnlyAliasShapeIsNotComparable(): void
    {
        self::assertFalse((new ReflectionClass(AliasShape::class))->implementsInterface(ComparableShape::class));
        self::assertTrue((new ReflectionClass(ApplicationShape::class))->implementsInterface(ComparableShape::class));
        self::assertTrue((new ReflectionClass(VariableShape::class))->implementsInterface(ComparableShape::class));
        self::assertTrue((new ReflectionClass(StructShape::class))->implementsInterface(ComparableShape::class));
        self::assertTrue((new ReflectionClass(Signature::class))->implementsInterface(ComparableShape::class));
    }

    /**
     * {@see VariableShape::bind()} is what actually records a variable's binding -- {@see Type::bind()} no longer
     * special-cases a variable before dispatching to it, so this is the one implementation of the five that isn't
     * unreachable: the first type it's matched against is the one that's kept, unconditionally, since a variable's
     * own shape has nothing to compare $actual against.
     */
    public function testVariableShapeBindRecordsTheFirstBindingDirectly(): void
    {
        $variable = new VariableShape('T');

        self::assertEquals(['T' => Type::int()], $variable->bind(Type::int(), []));
        self::assertEquals(['T' => Type::int()], $variable->bind(Type::string(), ['T' => Type::int()]));
    }
}
