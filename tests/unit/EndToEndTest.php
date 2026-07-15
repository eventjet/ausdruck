<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Test\Unit;

use Eventjet\Ausdruck\Parser\Declarations;
use Eventjet\Ausdruck\Parser\ExpressionParser;
use Eventjet\Ausdruck\Scope;
use Eventjet\Ausdruck\Type;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EndToEndTest extends TestCase
{
    /**
     * @return iterable<string, array{E2eCase}>
     */
    public static function cases(): iterable
    {
        foreach (E2eCase::all() as $name => $case) {
            yield $name => [$case];
        }
    }

    #[DataProvider('cases')]
    public function testRun(E2eCase $case): void
    {
        $expression = ExpressionParser::parse($case->source, new Declarations(types: $case->types));

        /** @var mixed $actual */
        $actual = $expression->evaluate(new Scope($case->input));

        self::assertEquals($case->expected, $actual);
    }

    /**
     * A grouped lambda is a real, evaluable call target: `doCall` invokes its receiver, so
     * `(|x| x:string).doCall:string("foo")` runs the lambda with "foo" and yields "foo". Without the group the
     * `.doCall` would bind inside the body and describe a different expression, so printing the tree back out has to
     * keep the parentheses—re-parsing the printed form has to land on the same tree and evaluate to the same value.
     */
    public function testGroupedLambdaIsAnEvaluableCallTargetThatSurvivesRoundTripping(): void
    {
        $doCall = Type::func(Type::string(), [Type::func(Type::string(), [Type::string()]), Type::string()]);
        $declarations = new Declarations(functions: ['doCall' => $doCall]);
        $scope = new Scope([], ['doCall' => static fn(callable $fn, string $arg): mixed => $fn($arg)]);

        $expression = ExpressionParser::parse('(|x| x:string).doCall:string("foo")', $declarations);
        $reparsed = ExpressionParser::parse((string)$expression, $declarations);

        self::assertSame('foo', $expression->evaluate($scope));
        self::assertSame('foo', $reparsed->evaluate($scope));
    }
}
