<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Test\Unit\Parser;

use Eventjet\Ausdruck\Parser\Declarations;
use Eventjet\Ausdruck\Parser\ExpressionParser;
use Eventjet\Ausdruck\Parser\Types;
use Eventjet\Ausdruck\Signature;
use Eventjet\Ausdruck\Test\Unit\ParsesTypeSyntax;
use Eventjet\Ausdruck\Type;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DeclarationsTest extends TestCase
{
    use ParsesTypeSyntax;

    public function testCanNotOverrideBuiltInFunctions(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Can\'t override built-in function substr');

        new Declarations(functions: ['substr' => Type::func(Type::string(), [Type::int()])]);
    }

    /**
     * A function without a function type is not a function anyone could ever call: rejecting it here, rather than
     * downgrading it to "undeclared" wherever it's read, means every {@see Signature} in {@see Declarations::$functions}
     * really is one the receiver and argument checks can trust.
     */
    public function testFunctionMustBeDeclaredWithAFunctionType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('foo is declared as int, which is not a function type');

        new Declarations(functions: ['foo' => Type::int()]);
    }

    /**
     * {@see Type::func()} never claims a binder of its own, so declaring a function with one is what quantifies it:
     * {@see Signature::quantified()} derives `myHead`'s binder from `T`, the same variable the parameters and return
     * type actually reach, rather than requiring the declaration to be built any differently from a nested function
     * type.
     */
    public function testDeclaringAFunctionDerivesItsBinderFromWhatItReaches(): void
    {
        $t = Type::var('T');

        $declarations = new Declarations(functions: ['myHead' => Type::func($t, [Type::listOf($t)])]);

        self::assertSame('fn<T>(list<T>) -> T', (string)$declarations->functions['myHead']);
    }

    /**
     * A variable's declared type, unlike a function's, is never quantified: there is no `fn<...>` binder here for
     * {@see Type::var()} to belong to, whether the variable sits bare or, as here, nested inside a list wrapping a
     * function type nothing has quantified either.
     */
    public function testAVariableWhoseTypeReachesAFreeVariableIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'items is declared as list<fn(T) -> bool>, which reaches a type variable nothing captures -- a '
                . 'variable\'s declared type is never quantified the way a function\'s own declaration or a '
                . 'Type::alias() target is, so nothing here would ever bind it',
        );

        $t = Type::var('T');
        new Declarations(variables: ['items' => Type::listOf(Type::func(Type::bool(), [$t]))]);
    }

    /**
     * The written-binder counterpart to {@see self::testDeclaringAFunctionDerivesItsBinderFromWhatItReaches()}: a
     * function type parsed from a written `fn<...>` already carries the binder
     * {@see \Eventjet\Ausdruck\Parser\TypeResolution::resolveSignature()} stored, and {@see Signature::quantified()}
     * keeps a binder that's already there rather than re-deriving one from a left-to-right walk -- so declaring
     * `foo` prints its binder back in the order it was written, `U` before `T`, even though that walk would meet `T`
     * first, in `list<T>`.
     */
    public function testDeclaringAParsedFunctionKeepsTheWrittenBinderOrder(): void
    {
        $nodes = self::parseTypeDeclarations('foo: fn<U, T>(list<T>, fn(T) -> U) -> list<U>');
        $type = (new Types())->resolve($nodes['foo']);
        self::assertInstanceOf(Type::class, $type);

        $declarations = new Declarations(functions: ['foo' => $type]);

        self::assertSame('fn<U, T>(list<T>, fn(T) -> U) -> list<U>', (string)$declarations->functions['foo']);
    }

    /**
     * $g already owns a binder of its own -- parsed from written `fn<T>(T) -> T` syntax -- but the binder
     * {@see Signature::quantified()} derives for `f` around it still may not reuse the same name T: `f`'s derived
     * binder encloses $g, so a shared T would shadow $g's own, and shadowing an enclosing variable is not allowed --
     * the same rule {@see \Eventjet\Ausdruck\Parser\TypeResolution::checkTypeVariable()} enforces for a written
     * `fn<...>` (see generics/type-variable/reusing-an-enclosing-binders-name). `f` would otherwise print as
     * `fn<T>(fn<T>(T) -> T, T) -> T`, which the parser reads back as a redeclared variable, so it is rejected as it
     * is built rather than handed back as a signature that can't round-trip.
     */
    public function testADerivedBinderMayNotReuseANestedSignaturesOwnBinderName(): void
    {
        $g = ExpressionParser::parse('x:fn<T>(T) -> T')->getType();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'The binder derived for fn<T>(fn<T>(T) -> T, T) -> T would introduce T, which a nested function type '
                . 'already declares -- the inner one would shadow the outer variable, so the type could never be read '
                . 'back the way it prints',
        );

        new Declarations(functions: ['f' => Type::func(Type::var('T'), [$g, Type::var('T')])]);
    }

    /**
     * The written-binder counterpart already rejects a binder that shadows a registered alias
     * ({@see \Eventjet\Ausdruck\Parser\TypeResolution::checkTypeVariable()}) -- a builder-constructed function's own
     * derived binder never passes through that check, since {@see Type::func()} and {@see Type::var()} see neither
     * the enclosing {@see Types} nor each other, so {@see Declarations} is the one seam left to ask the same
     * question of it.
     */
    public function testADerivedBinderThatShadowsARegisteredAliasIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'f is declared as fn(Foo) -> Foo, whose binder would have to introduce Foo -- but Foo already names a '
                . 'type, so a call could never tell the two apart',
        );

        $types = new Types(['Foo' => Type::int()]);
        new Declarations(types: $types, functions: ['f' => Type::func(Type::var('Foo'), [Type::var('Foo')])]);
    }

    /**
     * The alias-shadowing check reaches every name the derived binder introduces, not just the first: a variable that
     * doesn't name a type is passed over so the walk can go on to one that does, rather than the walk stopping at it.
     * Here `T` names no alias and comes first in the binder derived from `fn(T, Foo) -> int`, while `Foo` -- which
     * does -- comes second, so a check that gave up at `T` would never reach the collision `Foo` is.
     */
    public function testADerivedBinderIsCheckedForAliasShadowingPastItsFirstName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'f is declared as fn(T, Foo) -> int, whose binder would have to introduce Foo -- but Foo already names a '
                . 'type, so a call could never tell the two apart',
        );

        $types = new Types(['Foo' => Type::int()]);
        new Declarations(types: $types, functions: ['f' => Type::func(Type::int(), [Type::var('T'), Type::var('Foo')])]);
    }
}
