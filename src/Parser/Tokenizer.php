<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Parser;

use function array_map;
use function array_shift;
use function assert;
use function chr;
use function ctype_space;
use function current;
use function implode;
use function in_array;
use function is_numeric;
use function next;
use function ord;
use function sprintf;
use function str_contains;
use function str_split;

/**
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class Tokenizer
{
    public const NON_IDENTIFIER_CHARS = [
        self::DOT,
        self::OPEN_BRACKET,
        self::CLOSE_BRACKET,
        self::OPEN_PAREN,
        self::CLOSE_PAREN,
        self::DOUBLE_QUOTE,
        self::EQUALS,
        self::PIPE,
        self::OPEN_ANGLE,
        self::CLOSE_ANGLE,
        self::OPEN_BRACE,
        self::CLOSE_BRACE,
        self::COLON,
        self::COMMA,
        self::SPACE,
        self::MINUS,
    ];

    private const DOT = 0x2e;
    private const OPEN_PAREN = 0x28;
    private const CLOSE_PAREN = 0x29;
    private const OPEN_ANGLE = 0x3c;
    private const CLOSE_ANGLE = 0x3e;
    private const COLON = 0x3a;
    private const COMMA = 0x2c;
    private const OPEN_BRACKET = 0x5b;
    private const CLOSE_BRACKET = 0x5d;
    private const DOUBLE_QUOTE = 0x22;
    private const NEWLINE = 0x0a;
    private const EQUALS = 0x3d;
    private const PIPE = 0x7c;
    private const AMPERSAND = 0x26;
    private const OPEN_BRACE = 0x7b;
    private const CLOSE_BRACE = 0x7d;
    private const SPACE = 0x20;
    private const MINUS = 0x2d;
    private const ZERO = 0x30;
    private const NINE = 0x39;

    /** @var int<1, max> */
    private int $line = 1;
    /** @var int<1, max> */
    private int $column = 1;

    /**
     * @param list<int> $chars
     */
    public function __construct(private array &$chars)
    {
    }

    /**
     * @return iterable<ParsedToken>
     */
    public static function tokenize(string $src): iterable
    {
        $chars = array_map(ord(...), str_split($src));
        return (new self($chars))->doTokenize();
    }

    /**
     * @return list<int>
     */
    private static function bytes(string $expected): array
    {
        return array_map(ord(...), str_split($expected));
    }

    /**
     * @param list<int> $bytes
     */
    private static function bytesToString(array $bytes): string
    {
        return implode('', array_map(chr(...), $bytes));
    }

    /**
     * @return iterable<ParsedToken>
     */
    private function doTokenize(): iterable
    {
        while (true) {
            $char = $this->peek();
            if ($char === null) {
                break;
            }
            $singleCharToken = match ($char) {
                self::DOT => Token::Dot,
                self::OPEN_PAREN => Token::OpenParen,
                self::CLOSE_PAREN => Token::CloseParen,
                self::OPEN_ANGLE => Token::OpenAngle,
                self::CLOSE_ANGLE => Token::CloseAngle,
                self::COLON => Token::Colon,
                self::COMMA => Token::Comma,
                self::OPEN_BRACKET => Token::OpenBracket,
                self::CLOSE_BRACKET => Token::CloseBracket,
                default => null,
            };
            if ($singleCharToken !== null) {
                $this->next();
                yield new ParsedToken($singleCharToken, $this->line, $this->column);
                $this->column++;
                continue;
            }
            if (ctype_space(chr($char))) {
                $this->next();
                if ($char === self::NEWLINE) {
                    $this->line++;
                    $this->column = 1;
                } else {
                    $this->column++;
                }
                continue;
            }
            if ($char === self::DOUBLE_QUOTE) {
                $startLine = $this->line;
                $startCol = $this->column;
                $this->next();
                $this->column++;
                yield new ParsedToken($this->string(), $startLine, $startCol);
                continue;
            }
            if ($char === self::EQUALS) {
                $startCol = $this->column;
                $token = $this->equals();
                yield new ParsedToken($token, $this->line, $startCol);
                continue;
            }
            if ($char === self::MINUS || ($char >= self::ZERO && $char <= self::NINE)) {
                $startCol = $this->column;
                yield new ParsedToken($this->numberOrArrow(), $this->line, $startCol);
                continue;
            }
            if ($char === self::PIPE) {
                $this->next();
                $char = $this->peek();
                if ($char === self::PIPE) {
                    $this->next();
                    yield new ParsedToken(Token::Or, $this->line, $this->column);
                    $this->column += 2;
                } else {
                    yield new ParsedToken(Token::Pipe, $this->line, $this->column);
                    $this->column++;
                }
                continue;
            }
            if ($char === self::AMPERSAND) {
                $this->next();
                $char = $this->peek();
                if ($char === self::AMPERSAND) {
                    $this->next();
                    yield new ParsedToken(Token::And, $this->line, $this->column);
                    $this->column += 2;
                } else {
                    throw SyntaxError::create('Unexpected character &', Span::char($this->line, $this->column));
                }
                continue;
            }
            if (!in_array($char, self::NON_IDENTIFIER_CHARS, true)) {
                $startCol = $this->column;
                yield new ParsedToken($this->identifier(), $this->line, $startCol);
                continue;
            }
            throw SyntaxError::create(sprintf('Unexpected character %s', $char), Span::char($this->line, $this->column));
        }
    }

    private function identifier(): string
    {
        $identifier = '';

        while (true) {
            $char = $this->peek();

            if ($char === null) {
                break;
            }

            if (ctype_space(chr($char)) || in_array($char, self::NON_IDENTIFIER_CHARS, true)) {
                break;
            }

            $identifier .= chr($char);
            $this->next();
            $this->column++;
        }

        return $identifier;
    }

    private function equals(): Token
    {
        $this->next();
        $this->column++;
        $this->expect('==');
        return Token::TripleEquals;
    }

    private function expect(string $expected): void
    {
        $expected = self::bytes($expected);
        $originalExpected = $expected;
        while (true) {
            if ($expected === []) {
                return;
            }
            $actualChar = $this->peek();
            $expectedChar = $expected[0];
            if ($actualChar !== $expectedChar) {
                throw SyntaxError::create(
                    $actualChar === null
                        ? sprintf('Expected %s, got end of input', self::bytesToString($originalExpected))
                        : sprintf('Expected %s, got %s', self::bytesToString($originalExpected), chr($actualChar)),
                    Span::char($this->line, $this->column),
                );
            }
            $this->next();
            assert($actualChar !== self::NEWLINE, 'We\'r never expecting newlines');
            $this->column++;
            array_shift($expected);
        }
    }

    /**
     * @return Literal<int | float> | Token
     */
    private function numberOrArrow(): Literal|Token
    {
        $number = '';
        while (true) {
            $char = $this->peek();
            if ($number === '' && $char === self::MINUS) {
                $number = chr($char);
                $this->next();
                $this->column++;
                continue;
            }
            if ($number === '-' && $char === self::CLOSE_ANGLE) {
                $this->next();
                return Token::Arrow;
            }
            if ($char === null || !is_numeric($number . chr($char))) {
                break;
            }
            $number .= chr($char);
            $this->next();
            $this->column++;
        }
        if ($number === '-') {
            return Token::Minus;
        }
        return new Literal(str_contains($number, '.') ? (float)$number : (int)$number);
    }

    /**
     * @return Literal<string>
     */
    private function string(): Literal
    {
        $string = '';
        while (true) {
            $char = $this->peek();
            if ($char === null) {
                throw SyntaxError::create('Expected closing quote', Span::char($this->line, $this->column));
            }
            if ($char === self::DOUBLE_QUOTE) {
                $this->next();
                $this->column++;
                break;
            }
            $string .= chr($char);
            $this->next();
            $this->column++;
        }
        return new Literal($string);
    }

    private function peek(): int|null
    {
        $char = current($this->chars);
        if ($char === false) {
            return null;
        }
        return $char;
    }

    private function next(): void
    {
        $char = current($this->chars);
        if ($char === false) {
            return;
        }
        next($this->chars);
    }
}
