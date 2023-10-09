<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Parser;

use function ctype_space;
use function is_numeric;
use function sprintf;
use function str_contains;
use function substr;

/**
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final readonly class Tokenizer
{
    public const NON_IDENTIFIER_CHARS = '.[]()"=|<>{}:, -';

    /**
     * @param iterable<mixed, string> $chars
     * @return iterable<Span<Token | string | Literal<string | int | float>>>
     */
    public static function tokenize(iterable $chars): iterable
    {
        $line = 0;
        $column = 0;
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
                self::next($chars, $line, $column);
                yield new Span($singleCharToken, $line, $column, $line, $column);
                continue;
            }
            if (ctype_space($char)) {
                self::next($chars, $line, $column);
                continue;
            }
            if ($char === '"') {
                yield self::string($chars, $line, $column);
                continue;
            }
            if ($char === '=') {
                yield self::equals($chars, $line, $column);
                continue;
            }
            if ($char === '-' || is_numeric($char)) {
                yield self::number($chars, $line, $column);
                continue;
            }
            if ($char === '|') {
                self::next($chars, $line, $column);
                $char = $chars->peek();
                if ($char === '|') {
                    self::next($chars, $line, $column);
                    yield new Span(Token::Or, $line, $column - 1, $line, $column);
                } else {
                    yield new Span(Token::Pipe, $line, $column - 1, $line, $column);
                }
                continue;
            }
            yield self::identifier($chars, $line, $column);
        }
    }

    /**
     * @param Peekable<string> $chars
     * @return Span<string>
     */
    private static function identifier(Peekable $chars, int &$line, int &$column): Span
    {
        $startLine = $line;
        $startColumn = $column;

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
            self::next($chars, $line, $column);
        }

        return new Span($identifier, $startLine, $startColumn, $line, $column);
    }

    /**
     * @param Peekable<string> $chars
     * @return Span<Token>
     */
    private static function equals(Peekable $chars, int &$line, int &$column): Span
    {
        self::next($chars, $line, $column);
        self::expect($chars, '==', $line, $column);
        return new Span(Token::TripleEquals, $line, $column - 2, $line, $column);
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
                );
            }
            self::next($chars, $line, $column);
            $expected = substr($expected, 1);
        }
    }

    /**
     * @param Peekable<string> $chars
     * @return Span<Literal<int | float> | Token>
     */
    private static function number(Peekable $chars, int &$line, int &$column): Span
    {
        $startLine = $line;
        $startColumn = $column;
        $number = '';
        while (true) {
            $char = $chars->peek();
            if ($number === '' && $char === '-') {
                $number = $char;
                self::next($chars, $line, $column);
                continue;
            }
            if ($char === null || !is_numeric($number . $char)) {
                $endLine = $line;
                $endColumn = $column;
                break;
            }
            $number .= $char;
            self::next($chars, $line, $column);
        }
        if ($number === '-') {
            /**
             * @phpstan-ignore-next-line False positive. $endLine and $endColumn are always defined.
             * @psalm-suppress MixedArgument False positive
             * @psalm-suppress PossiblyUndefinedVariable False positive
             */
            return new Span(Token::Minus, $startLine, $startColumn, $endLine, $endColumn);
        }
        $literal = new Literal(str_contains($number, '.') ? (float)$number : (int)$number);
        /**
         * @phpstan-ignore-next-line False positive. $endLine and $endColumn are always defined.
         * @psalm-suppress MixedArgument False positive
         * @psalm-suppress PossiblyUndefinedVariable False positive
         */
        return new Span($literal, $startLine, $startColumn, $endLine, $endColumn);
    }

    /**
     * @param Peekable<string> $chars
     * @return Span<Literal<string>>
     */
    private static function string(Peekable $chars, int &$line, int &$column): Span
    {
        $startLine = $line;
        $startColumn = $column;
        self::expect($chars, '"', $line, $column);
        $string = '';
        while (true) {
            $char = $chars->peek();
            if ($char === null) {
                throw new SyntaxError('Expected closing quote');
            }
            if ($char === '"') {
                $endLine = $line;
                $endColumn = $column;
                self::next($chars, $line, $column);
                break;
            }
            $string .= $char;
            self::next($chars, $line, $column);
        }
        /**
         * @phpstan-ignore-next-line False positive. $endLine and $endColumn are always defined.
         * @psalm-suppress MixedArgument False positive
         * @psalm-suppress PossiblyUndefinedVariable False positive
         */
        return new Span(new Literal($string), $startLine, $startColumn, $endLine, $endColumn);
    }

    /**
     * @param Peekable<string> $chars
     */
    private static function next(Peekable $chars, int &$line, int &$column): void
    {
        $char = $chars->next();
        if ($char === "\n") {
            $line++;
            $column = 0;
        } else {
            $column++;
        }
    }
}
