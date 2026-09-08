<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Test\Unit;

use Eventjet\Ausdruck\EvaluationError;
use Eventjet\Ausdruck\Formatter\ExpressionFormatter;
use Eventjet\Ausdruck\Parser\Declarations;
use Eventjet\Ausdruck\Parser\ExpressionParser;
use Eventjet\Ausdruck\Parser\SyntaxError;
use Eventjet\Ausdruck\Parser\TypeError;
use Eventjet\Ausdruck\Scope;
use Eventjet\Ausdruck\Type;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function sprintf;

final class EndToEndTest extends TestCase
{
    use AssertsEvaluatedValues;

    /**
     * @return iterable<string, array{E2eCase}>
     */
    public static function cases(): iterable
    {
        foreach (E2eCase::all() as $name => $case) {
            yield $name => [$case];
        }
    }

    /**
     * A case says what it expects by which sections it writes, so this runs the source as far as those sections reach:
     * a case that expects an error stops at the parse, and one that expects a type never has to be evaluated.
     *
     * A case that expects an error is asserted by catching it and comparing the message with assertSame rather than
     * PHPUnit's expectExceptionMessage, which only asserts a substring: a case that pins `Unknown type T` would still
     * pass against a message that merely contains `Unknown type`, and every fixture here exists to pin an exact
     * message.
     */
    #[DataProvider('cases')]
    public function testRun(E2eCase $case): void
    {
        if ($case->error !== null) {
            try {
                $expression = ExpressionParser::parse($case->source, $case->declarations);
                if ($case->error->class === EvaluationError::class) {
                    $expression->evaluate(new Scope($case->input));
                }
            } catch (SyntaxError|TypeError|EvaluationError $e) {
                self::assertInstanceOf($case->error->class, $e);
                self::assertSame($case->error->message, $e->getMessage());
                return;
            }
            self::fail(sprintf('Expected %s, but the source was accepted', $case->error->class));
        }

        $expression = ExpressionParser::parse($case->source, $case->declarations);

        if ($case->printed !== null) {
            self::assertSame($case->printed, (string)$expression);
            $reparsed = ExpressionParser::parse($case->printed, $case->declarations);
            self::assertTrue($expression->equals($reparsed));
            self::assertTrue($expression->getType()->equals($reparsed->getType()));
        }

        if ($case->expressionType !== null) {
            self::assertSame($case->expressionType, (string)$expression->getType());
        }
        if ($case->formatted !== null) {
            $formatted = ExpressionFormatter::format($expression, $case->width);
            self::assertSame($case->formatted, $formatted);
            self::assertTrue(
                $expression->equals(ExpressionParser::parse($formatted, $case->declarations)),
                'Formatting an expression has to leave a source that reads back as the same expression',
            );
        }
        if ($case->output === null) {
            return;
        }
        /** @var mixed $actual */
        $actual = $expression->evaluate(new Scope($case->input));
        self::assertEvaluatesTo($case->output->value(), $actual);
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
