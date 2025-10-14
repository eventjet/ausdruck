<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Test\Unit;

use Eventjet\Ausdruck\Parser\ExpressionParser;
use Eventjet\Ausdruck\Scope;
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
            ;
            yield $name => [$case];
        }
    }

    #[DataProvider('cases')]
    public function testFoo(E2eCase $case): void
    {
        $expression = ExpressionParser::parse($case->source);

        /** @var mixed $actual */
        $actual = $expression->evaluate(new Scope($case->input));

        self::assertSame($case->expected, $actual);
    }
}
