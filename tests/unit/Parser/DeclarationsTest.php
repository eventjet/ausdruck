<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Test\Unit\Parser;

use Eventjet\Ausdruck\Parser\Declarations;
use Eventjet\Ausdruck\Parser\TypeParser;
use Eventjet\Ausdruck\Parser\Types;
use Eventjet\Ausdruck\Signature;
use Eventjet\Ausdruck\Type;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DeclarationsTest extends TestCase
{
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
        /**
         * @psalm-suppress InternalClass
         * @psalm-suppress InternalMethod
         */
        $nodes = TypeParser::parseDeclarations('foo: fn<U, T>(list<T>, fn(T) -> U) -> list<U>');
        $type = (new Types())->resolve($nodes['foo']);
        self::assertInstanceOf(Type::class, $type);

        $declarations = new Declarations(functions: ['foo' => $type]);

        self::assertSame('fn<U, T>(list<T>, fn(T) -> U) -> list<U>', (string)$declarations->functions['foo']);
    }
}
