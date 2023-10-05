<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Test\Unit\Parser;

use Eventjet\Ausdruck\Parser\Literal;
use Eventjet\Ausdruck\Parser\Token;
use PHPUnit\Framework\TestCase;

final class TokenTest extends TestCase
{
    /**
     * @return iterable<string, array{Token | string | Literal<string | int | float>, string}>
     */
    public static function printCases(): iterable
    {
        $tokenCases = [
            [Token::Dot, '.'],
            [Token::TripleEquals, '==='],
            [Token::Quote, '"'],
            [Token::OpenParen, '('],
            [Token::CloseParen, ')'],
            [Token::OpenBracket, '['],
            [Token::CloseBracket, ']'],
            [Token::OpenAngle, '<'],
            [Token::CloseAngle, '>'],
            [Token::Pipe, '|'],
            [Token::Comma, ','],
            [Token::Colon, ':'],
        ];
        foreach ($tokenCases as [$token, $expected]) {
            yield $token->name => [$token, $expected];
        }
        yield 'identifier' => ['foo', 'foo'];
        yield 'String literal' => [new Literal('foo'), '"foo"'];
        yield 'Int literal' => [new Literal(42), '42'];
        yield 'Float literal' => [new Literal(42.23), '42.23'];
    }

    /**
     * @param Token|string|Literal<string | int | float> $token
     * @dataProvider printCases
     */
    public function testPrint(Token|string|Literal $token, string $expected): void
    {
        self::assertSame($expected, Token::print($token));
    }
}
