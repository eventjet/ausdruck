<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Test\Unit\Parser;

use Eventjet\Ausdruck\Parser\Literal;
use Eventjet\Ausdruck\Parser\Token;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TokenTest extends TestCase
{
    /**
     * @return iterable<string, array{Token | string | Literal<string | int | float>, string}>
     */
    public static function printCases(): iterable
    {
        // Every case, taken from the enum rather than listed here: a hand-written list is a copy of the enum that only
        // ever tests itself, and goes stale the moment a token is added—as it had, by ten cases. What is pinned is
        // that a token prints as its own backing value, for all of them and with no exception anywhere in the enum,
        // which is the whole of Token::print()'s first arm. It says nothing about what those backing values actually
        // are—that a token spells a particular piece of the language's syntax—which
        // {@see self::testEveryTokenIsSpelledAsDeclared()} pins instead, independently of the enum.
        foreach (Token::cases() as $token) {
            yield $token->name => [$token, $token->value];
        }
        yield 'identifier' => ['foo', 'foo'];
        yield 'String literal' => [new Literal('foo'), '"foo"'];
        yield 'Int literal' => [new Literal(42), '42'];
        yield 'Float literal' => [new Literal(42.23), '42.23'];
    }

    /**
     * The language's own operator and punctuation spellings, listed by hand. {@see self::printCases()} derives its
     * expectation from the enum, so it can only ever pin that print() is faithful to whatever a token's backing value
     * says—never what that value actually is. A typo here, such as `case NotEquals = '!=='` becoming `'!='`, would
     * otherwise be caught only indirectly, by parser tests, and the failure would point at the parser rather than at
     * the token that caused it. {@see self::testEveryTokenIsSpelledAsDeclared()} keeps this table from going stale
     * the way the hand-written list {@see self::printCases()} replaced did—a token missing from it, an extra entry
     * for one that no longer exists, and a wrong spelling all show up as one `assertSame` diff naming the token.
     *
     * @return array<string, string>
     */
    private static function spellings(): array
    {
        return [
            'Dot' => '.',
            'TripleEquals' => '===',
            'NotEquals' => '!==',
            'Not' => '!',
            'Quote' => '"',
            'OpenParen' => '(',
            'CloseParen' => ')',
            'OpenBracket' => '[',
            'CloseBracket' => ']',
            'OpenAngle' => '<',
            'CloseAngle' => '>',
            'LessThanEquals' => '<=',
            'GreaterThanEquals' => '>=',
            'OpenBrace' => '{',
            'CloseBrace' => '}',
            'Or' => '||',
            'And' => '&&',
            'Pipe' => '|',
            'Comma' => ',',
            'Colon' => ':',
            'Minus' => '-',
            'Plus' => '+',
            'Asterisk' => '*',
            'Slash' => '/',
            'Percent' => '%',
            'Arrow' => '->',
        ];
    }

    /**
     * @param Token|string|Literal<string | int | float> $token
     */
    #[DataProvider('printCases')]
    public function testPrint(Token|string|Literal $token, string $expected): void
    {
        self::assertSame($expected, Token::print($token));
    }

    /**
     * One fact, pinned once: every token's backing value is the spelling {@see self::spellings()} says. A missing
     * entry, a stale one, a wrong spelling, or the two lists disagreeing in order all fail this one `assertSame`,
     * with a diff that names the token that drifted.
     */
    public function testEveryTokenIsSpelledAsDeclared(): void
    {
        $actual = [];
        foreach (Token::cases() as $token) {
            $actual[$token->name] = $token->value;
        }
        self::assertSame(self::spellings(), $actual);
    }
}
