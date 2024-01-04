<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Parser;

use function assert;
use function ctype_space;
use function is_numeric;
use function sprintf;
use function str_contains;
use function substr;

/**
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class Tokenizer
{
    public const NON_IDENTIFIER_CHARS = '.[]()"=|<>{}:, -';

    /**
     * @param iterable<mixed, string> $chars
     * @return iterable<ParsedToken>
     */
    public static function tokenize(iterable $chars): iterable
    {
        $line = 1;
        $column = 1;
        $chars = new Peekable($chars);
        while (true) {
            $char = $chars->peek();
            if ($char === null) {
                break;
            }
            $singleCharToken = match ($char) {
                '.' => Token::Dot,
                '(' => Token::OpenParen,
                ')' => Token::CloseParen,
                '<' => Token::OpenAngle,
                '>' => Token::CloseAngle,
                ':' => Token::Colon,
                ',' => Token::Comma,
                default => null,
            };
            if ($singleCharToken !== null) {
                $chars->next();
                yield new ParsedToken($singleCharToken, $line, $column);
                $column++;
                continue;
            }
            if (ctype_space($char)) {
                $chars->next();
                if ($char === "\n") {
                    $line++;
                    $column = 1;
                } else {
                    $column++;
                }
                continue;
            }
            if ($char === '"') {
                $startLine = $line;
                $startCol = $column;
                $chars->next();
                $column++;
                yield new ParsedToken(self::string($chars, $line, $column), $startLine, $startCol);
                continue;
            }
            if ($char === '=') {
                $token = self::equals($chars, $line, $column);
                yield new ParsedToken($token, $line, $column - 3);
                continue;
            }
            if ($char === '-' || is_numeric($char)) {
                $startCol = $column;
                yield new ParsedToken(self::number($chars, $column), $line, $startCol);
                continue;
            }
            if ($char === '|') {
                $chars->next();
                $char = $chars->peek();
                if ($char === '|') {
                    $chars->next();
                    yield new ParsedToken(Token::Or, $line, $column);
                    $column += 2;
                } else {
                    yield new ParsedToken(Token::Pipe, $line, $column);
                    $column++;
                }
                continue;
            }
            $startCol = $column;
            yield new ParsedToken(self::identifier($chars, $column), $line, $startCol);
        }
    }

    /**
     * @param Peekable<string> $chars
     */
    private static function identifier(Peekable $chars, int &$column): string
    {
        $identifier = '';

        while (true) {
            $char = $chars->peek();

            if ($char === null) {
                break;
            }

            if (ctype_space($char) || str_contains(self::NON_IDENTIFIER_CHARS, $char)) {
                break;
            }

            $identifier .= $char;
            $chars->next();
            $column++;
        }

        return $identifier;
    }

    /**
     * @param Peekable<string> $chars
     */
    private static function equals(Peekable $chars, int &$line, int &$column): Token
    {
        $chars->next();
        $column++;
        self::expect($chars, '==', $line, $column);
        return Token::TripleEquals;
    }

    /**
     * @param Peekable<string> $chars
     */
    private static function expect(Peekable $chars, string $expected, int &$line, int &$column): void
    {
        $originalExpected = $expected;
        while (true) {
            if ($expected === '') {
                return;
            }
            $actualChar = $chars->peek();
            $expectedChar = $expected[0];
            if ($actualChar !== $expectedChar) {
                throw new SyntaxError(
                    $actualChar === null
                        ? sprintf('Expected %s, got end of input', $originalExpected)
                        : sprintf('Expected %s, got %s', $originalExpected, $actualChar),
                    Span::char($line, $column),
                );
            }
            $chars->next();
            assert($actualChar !== "\n", 'We\'r never expecting newlines');
            $column++;
            $expected = substr($expected, 1);
        }
    }

    /**
     * @param Peekable<string> $chars
     * @return Literal<int | float> | Token
     */
    private static function number(Peekable $chars, int &$column): Literal|Token
    {
        $number = '';
        while (true) {
            $char = $chars->peek();
            if ($number === '' && $char === '-') {
                $number = $char;
                $chars->next();
                $column++;
                continue;
            }
            if ($char === null || !is_numeric($number . $char)) {
                break;
            }
            $number .= $char;
            $chars->next();
            $column++;
        }
        if ($number === '-') {
            return Token::Minus;
        }
        return new Literal(str_contains($number, '.') ? (float)$number : (int)$number);
    }

    /**
     * @param Peekable<string> $chars
     * @return Literal<string>
     */
    private static function string(Peekable $chars, int &$line, int &$column): Literal
    {
        $string = '';
        while (true) {
            $char = $chars->peek();
            if ($char === null) {
                throw new SyntaxError('Expected closing quote', Span::char($line, $column));
            }
            if ($char === '"') {
                $chars->next();
                $column++;
                break;
            }
            $string .= $char;
            $chars->next();
            $column++;
        }
        return new Literal($string);
    }
}
