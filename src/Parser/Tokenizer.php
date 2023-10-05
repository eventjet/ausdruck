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
 */
final readonly class Tokenizer
{
    public const NON_IDENTIFIER_CHARS = '.[]()"=|<>{}:, -';

    /**
     * @param iterable<mixed, string> $chars
     * @return iterable<Token | string | Literal<string | int | float>>
     */
    public static function tokenize(iterable $chars): iterable
    {
        $chars = new Peekable($chars);
        while (true) {
            $char = $chars->peek();
            if ($char === null) {
                break;
            }
            $singleCharToken = match ($char) {
                '.' => Token::Dot,
                '[' => Token::OpenBracket,
                ']' => Token::CloseBracket,
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
                yield $singleCharToken;
                continue;
            }
            if (ctype_space($char)) {
                $chars->next();
                continue;
            }
            if ($char === '"') {
                $chars->next();
                yield self::string($chars);
                continue;
            }
            if ($char === '=') {
                yield self::equals($chars);
                continue;
            }
            if ($char === '-' || is_numeric($char)) {
                yield self::number($chars);
                continue;
            }
            if ($char === '|') {
                $chars->next();
                $char = $chars->peek();
                if ($char === '|') {
                    $chars->next();
                    yield Token::Or;
                } else {
                    yield Token::Pipe;
                }
                continue;
            }
            yield self::identifier($chars);
        }
    }

    /**
     * @param Peekable<string> $chars
     */
    private static function identifier(Peekable $chars): string
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
        }

        return $identifier;
    }

    /**
     * @param Peekable<string> $chars
     */
    private static function equals(Peekable $chars): Token
    {
        $chars->next();
        self::expect($chars, '==');
        return Token::TripleEquals;
    }

    /**
     * @param Peekable<string> $chars
     */
    private static function expect(Peekable $chars, string $expected): void
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
            $chars->next();
            $expected = substr($expected, 1);
        }
    }

    /**
     * @param Peekable<string> $chars
     * @return Literal<int | float> | Token
     */
    private static function number(Peekable $chars): Literal|Token
    {
        $number = '';
        while (true) {
            $char = $chars->peek();
            if ($number === '' && $char === '-') {
                $number = $char;
                $chars->next();
                continue;
            }
            if ($char === null || !is_numeric($number . $char)) {
                break;
            }
            $number .= $char;
            $chars->next();
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
    private static function string(Peekable $chars): Literal
    {
        $string = '';
        while (true) {
            $char = $chars->peek();
            if ($char === null) {
                throw new SyntaxError('Expected closing quote');
            }
            if ($char === '"') {
                $chars->next();
                break;
            }
            $string .= $char;
            $chars->next();
        }
        return new Literal($string);
    }
}
