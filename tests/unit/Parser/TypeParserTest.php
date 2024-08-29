<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Test\Unit\Parser;

use Eventjet\Ausdruck\Parser\Span;
use Eventjet\Ausdruck\Parser\SyntaxError;
use Eventjet\Ausdruck\Parser\TypeParser;
use LogicException;
use PHPUnit\Framework\TestCase;

use function explode;
use function implode;
use function preg_match;
use function strlen;

final class TypeParserTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function syntaxErrorCases(): iterable
    {
        yield 'End of string after function argument' => [
            'fn(int',
            'Expected ), got end of input',
        ];
        yield 'End of string after function arrow' => [
            'fn(int) ->',
            'Expected return type, got end of input',
        ];
        yield 'Dot after function arrow' => [
            'fn(int) -> .',
            'Expected return type, got .',
        ];
        yield 'Empty string' => [
            <<<'AUSDRUCK'
            
            =
            AUSDRUCK,
            'Invalid type ""',
        ];
        yield 'Whitespace-only string' => [
            '  ',
            'Invalid type ""',
        ];
        yield 'Arrow' => [
            <<<'AUSDRUCK'
            ->
            ==
            AUSDRUCK,
            'Expected type, got ->',
        ];
    }

    /**
     * @dataProvider syntaxErrorCases
     */
    public function testSyntaxErrors(string $type, string $expectedMessage): void
    {
        $expectedSpan = null;
        $lines = explode("\n", $type);
        foreach ($lines as $lineIndex => $line) {
            $result = preg_match('/^(?<indent>\s*)(?<marker>=+)\s*$/', $line, $matches);
            if ($result !== 1) {
                continue;
            }
            if ($expectedSpan !== null) {
                throw new LogicException('Multi-line markers are not implemented');
            }
            $lineNumber = $lineIndex;
            if ($lineNumber < 1) {
                throw new LogicException('A marker in the first line does not make sense');
            }
            $startCol = strlen($matches['indent']) + 1;
            $endCol = strlen($matches['indent']) + strlen($matches['marker']);
            /** @psalm-suppress InvalidArgument False positive */
            $expectedSpan = new Span($lineNumber, $startCol, $lineNumber, $endCol);
            unset($lines[$lineIndex]);
        }
        $type = implode("\n", $lines);

        /**
         * @psalm-suppress InternalClass
         * @psalm-suppress InternalMethod
         */
        $error = TypeParser::parseString($type);

        self::assertInstanceOf(SyntaxError::class, $error);
        self::assertSame($expectedMessage, $error->getMessage());
        if ($expectedSpan !== null) {
            self::assertSame((string)$expectedSpan, (string)$error->location);
        }
    }
}
