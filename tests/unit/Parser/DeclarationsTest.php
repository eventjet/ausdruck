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
     * {@see Type::nestedFunc()} defers every variable it reaches to whichever signature encloses it -- correct for a
     * function type nested inside another one's own parameters or return type, but wrong here: a declaration is
     * exactly the position nothing encloses. Built this way, the signature would read back with an empty binder
     * despite reaching `T`, and every call would silently see `T` as `any` instead of having it decided by the
     * argument -- the same defect {@see Type::func()} and {@see Type::genericFunc()} both already prevent for a
     * caller that reaches for the right door.
     */
    public function testAFunctionDeclaredThroughTheNestedDoorIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'myHead is declared as fn(list<T>) -> T, built through Type::nestedFunc(), which leaves it without a '
                . 'binder of its own -- a declaration needs Type::func() or Type::genericFunc() instead',
        );

        $t = Type::var('T');
        new Declarations(functions: ['myHead' => Type::nestedFunc($t, [Type::listOf($t)])]);
    }

    /**
     * The same wrong-door hole as {@see self::testAFunctionDeclaredThroughTheNestedDoorIsRejected()}, but for a
     * variable, whose declared type doesn't have to be a function at all: here it's a list of one, so the variable
     * {@see Type::nestedFunc()} defers is buried a level deeper than a direct {@see Type::asFunction()} check would
     * ever look.
     */
    public function testAVariableWhoseTypeReachesTheNestedDoorIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'items is declared as list<fn(T) -> bool>, which reaches a type variable nothing captures -- every '
                . 'function type it reaches through Type::nestedFunc() needs its own binder instead, via '
                . 'Type::func() or Type::genericFunc()',
        );

        $t = Type::var('T');
        new Declarations(variables: ['items' => Type::listOf(Type::nestedFunc(Type::bool(), [$t]))]);
    }
}
