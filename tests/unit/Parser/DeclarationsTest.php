<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Test\Unit\Parser;

use Eventjet\Ausdruck\Parser\Declarations;
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
     * downgrading it to "undeclared" wherever it's read, means every {@see Type} in {@see Declarations::$functions}
     * really is one the receiver and argument checks can trust.
     */
    public function testFunctionMustBeDeclaredWithAFunctionType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('foo is declared as int, which is not a function type');

        new Declarations(functions: ['foo' => Type::int()]);
    }

    /**
     * A declaration built straight through the PHP API, rather than parsed from a type string, has no parser to
     * reject a variable no binder declares -- see {@see \Eventjet\Ausdruck\Test\Unit\TypeTest::testAsFunctionRejectsAVariableItsOwnBinderDoesntDeclare()}.
     * {@see Declarations} reads every declared {@see Type} through {@see Type::asFunction()}, so the same rejection
     * still happens here, at construction, rather than being deferred to whatever call site happens to notice.
     */
    public function testFunctionCantDeclareAVariableItsOwnBinderDoesntDeclare(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('T isn\'t declared by this function type\'s own binder, so nothing quantifies it');

        new Declarations(functions: ['idish' => Type::func(Type::var('T'), [Type::listOf(Type::var('T'))])]);
    }
}
