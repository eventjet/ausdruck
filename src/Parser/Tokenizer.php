<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Parser;

use function assert;
use function ctype_space;
use function is_numeric;
use function ord;
use function sprintf;
use function str_contains;
use function substr;

/**
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class Tokenizer
{
    private const LOWER_A = 97;
    private const LOWER_Z = 122;
    private const UPPER_A = 65;
    private const UPPER_Z = 90;
    private const ZERO = 48;
    private const NINE = 57;
    private const UNDERSCORE = 95;

    /**
     * @param iterable<mixed, string> $chars
     * @return iterable<ParsedToken>
     */
    public static function tokenize(iterable $chars): iterable
    {
        /** @var positive-int $line */
        $line = 1;
        /** @var positive-int $column */
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
                '[' => Token::OpenBracket,
                ']' => Token::CloseBracket,
                '{' => Token::OpenBrace,
                '}' => Token::CloseBrace,
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
                $startCol = $column;
                $token = self::equals($chars, $line, $column);
                yield new ParsedToken($token, $line, $startCol);
                continue;
            }
            if ($char === '-' || is_numeric($char)) {
                $startCol = $column;
                yield new ParsedToken(self::numberOrArrow($chars, $column), $line, $startCol);
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
            if ($char === '&') {
                $chars->next();
                $char = $chars->peek();
                if ($char === '&') {
                    $chars->next();
                    yield new ParsedToken(Token::And, $line, $column);
                    $column += 2;
                } else {
                    throw SyntaxError::create('Unexpected character &', Span::char($line, $column));
                }
                continue;
            }
            if (self::isIdentifierChar($char, first: true)) {
                $startCol = $column;
                yield new ParsedToken(self::identifier($chars, $line, $column), $line, $startCol);
                continue;
            }
            throw SyntaxError::create(sprintf('Unexpected character %s', $char), Span::char($line, $column));
        }
    }

    /**
     * @param Peekable<string> $chars
     * @param positive-int $line
     * @param positive-int $column
     */
    private static function identifier(Peekable $chars, int $line, int &$column): string
    {
        $identifier = '';

        while (true) {
            $char = $chars->peek();

            if ($char === null) {
                break;
            }

            // No idea why it works if "first" is always false, but it
            // does, The error is probably caught somewhere else.
            if (ctype_space($char) || !self::isIdentifierChar($char, first: false)) {
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
     * @param positive-int $line
     * @param positive-int $column
     */
    private static function equals(Peekable $chars, int $line, int &$column): Token
    {
        $chars->next();
        $column++;
        self::expect($chars, '==', $line, $column);
        return Token::TripleEquals;
    }

    /**
     * @param Peekable<string> $chars
     * @param positive-int $line
     * @param positive-int $column
     */
    private static function expect(Peekable $chars, string $expected, int $line, int &$column): void
    {
        $originalExpected = $expected;
        while (true) {
            if ($expected === '') {
                return;
            }
            $actualChar = $chars->peek();
            $expectedChar = $expected[0];
            if ($actualChar !== $expectedChar) {
                throw SyntaxError::create(
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
     * @param positive-int $column
     * @return Literal<int | float> | Token
     */
    private static function numberOrArrow(Peekable $chars, int &$column): Literal|Token
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
            if ($number === '-' && $char === '>') {
                $chars->next();
                return Token::Arrow;
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
     * @param positive-int $line
     * @param positive-int $column
     * @return Literal<string>
     */
    private static function string(Peekable $chars, int $line, int &$column): Literal
    {
        $string = '';
        while (true) {
            $char = $chars->peek();
            if ($char === null) {
                throw SyntaxError::create('Expected closing quote', Span::char($line, $column));
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

    private static function isIdentifierChar(string $char, bool $first): bool
    {
        $byte = ord($char);
        $isChar = ($byte >= self::LOWER_A && $byte <= self::LOWER_Z) || ($byte >= self::UPPER_A && $byte <= self::UPPER_Z);
        if ($first) {
            return $isChar;
        }
        return $isChar
            || ($byte >= self::ZERO && $byte <= self::NINE)
            || $byte === self::UNDERSCORE;
    }
}
