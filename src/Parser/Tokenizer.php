<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Parser;

use function assert;
use function ctype_space;
use function is_numeric;
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
                yield new ParsedToken($singleCharToken, Span::char($line, $column));
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
                // Scanned before the span is built, not inside the ParsedToken() call: it is what moves $line and
                // $column to the end of the token, and reading them as arguments alongside it would make the extent
                // depend on PHP evaluating the arguments left to right.
                $string = self::string($chars, $line, $column);
                yield new ParsedToken($string, self::spanTo($startLine, $startCol, $line, $column));
                continue;
            }
            $startCol = $column;
            $multiCharToken = match ($char) {
                '=' => self::exact($chars, $line, $column, '===', Token::TripleEquals),
                '!' => self::exact($chars, $line, $column, '!==', Token::NotEquals),
                '&' => self::exact($chars, $line, $column, '&&', Token::And),
                '<' => self::angle($chars, $column, Token::OpenAngle, Token::LessThanEquals),
                '>' => self::angle($chars, $column, Token::CloseAngle, Token::GreaterThanEquals),
                /**
                 * The sign is never folded into a number literal: a `-` always yields Token::Minus, and
                 * {@see \Eventjet\Ausdruck\Expr::negative()} turns a negated number literal back into a negative one.
                 * If the sign were folded in here, whitespace would silently decide the meaning of `a -2`:
                 * subtraction, or `a` followed by the literal -2.
                 */
                '-' => self::bareOrPair($chars, $column, '>', Token::Minus, Token::Arrow),
                '|' => self::bareOrPair($chars, $column, '|', Token::Pipe, Token::Or),
                default => null,
            };
            if ($multiCharToken !== null) {
                yield new ParsedToken($multiCharToken, self::spanTo($line, $startCol, $line, $column));
                continue;
            }
            if (is_numeric($char)) {
                $number = self::number($chars, $column);
                yield new ParsedToken($number, self::spanTo($line, $startCol, $line, $column));
                continue;
            }
            if (self::isIdentifierChar($char, first: true)) {
                $identifier = self::identifier($chars, $line, $column);
                yield new ParsedToken($identifier, self::spanTo($line, $startCol, $line, $column));
                continue;
            }
            throw SyntaxError::create(sprintf('Unexpected character %s', $char), Span::char($line, $column));
        }
    }

    /**
     * The extent of a token that has just been scanned. Every scanner stops on the character after the token it read,
     * so that is where the extent ends: one column back.
     *
     * @param positive-int $startLine
     * @param positive-int $startColumn
     * @param positive-int $line Where the scanner stopped.
     * @param positive-int $column Where the scanner stopped: one past the token's last character.
     */
    private static function spanTo(int $startLine, int $startColumn, int $line, int $column): Span
    {
        $endColumn = $column - 1;
        // A scanner is only entered on a character that belongs to the token, and consumes it, so it always leaves
        // $column at least one past the start of a line.
        assert($endColumn >= 1);
        return new Span($startLine, $startColumn, $line, $endColumn);
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
     * Scans an operator that is the only token starting with its first character: `===`, `!==`, and `&&`. Once that
     * first character is there, the whole sequence is required; anything short of it is an error naming the operator
     * that was expected and underlining the characters that were actually read.
     *
     * @param Peekable<string> $chars
     * @param positive-int $line
     * @param positive-int $column
     * @param non-empty-string $sequence
     */
    private static function exact(Peekable $chars, int $line, int &$column, string $sequence, Token $token): Token
    {
        $startColumn = $column;
        $read = '';
        foreach (str_split($sequence) as $char) {
            if ($chars->peek() !== $char) {
                $endColumn = $column - 1;
                // The main loop only dispatches here after peeking $sequence's first character, so that one matched.
                assert($endColumn >= 1);
                throw SyntaxError::create(
                    sprintf('Expected %s, got %s', $sequence, $read),
                    new Span($line, $startColumn, $line, $endColumn),
                );
            }
            $read .= $char;
            $chars->next();
            $column++;
        }
        return $token;
    }

    /**
     * `<` and `>` each stand for two things: on their own they are the angle brackets of a generic type (which the
     * expression parser also reads as less-than and greater-than), and followed by `=` they are the comparison
     * operators `<=` and `>=`. The pair reading wins with one exception: two `=` after the angle mean a `===` follows,
     * as in `foo:list<int>===bar`, so the angle stays bare instead of stealing the first `=` and leaving behind a `==`
     * that is no token at all.
     *
     * @param Peekable<string> $chars
     * @param positive-int $column
     */
    private static function angle(Peekable $chars, int &$column, Token $bare, Token $pair): Token
    {
        $chars->next();
        $column++;
        if ($chars->peek() !== '=' || $chars->peek(1) === '=') {
            return $bare;
        }
        $chars->next();
        $column++;
        return $pair;
    }

    /**
     * A character that means one token on its own and another when $second completes it: `-` and `->`, `|` and `||`.
     * The pair always wins. For `->` it's the only reading that can be meant: no expression continues with a bare `-`
     * followed by a `>`. For `||` it's a choice: the two bars could also be the empty parameter list of a lambda, so
     * that list is written `| |`, and glued bars are the or they almost always are.
     *
     * @param Peekable<string> $chars
     * @param positive-int $column
     * @param non-empty-string $second The character that, if it comes next, makes this $pair instead of $bare.
     */
    private static function bareOrPair(Peekable $chars, int &$column, string $second, Token $bare, Token $pair): Token
    {
        $chars->next();
        $column++;
        if ($chars->peek() !== $second) {
            return $bare;
        }
        $chars->next();
        $column++;
        return $pair;
    }

    /**
     * @param Peekable<string> $chars
     * @param positive-int $column
     * @return Literal<int | float>
     */
    private static function number(Peekable $chars, int &$column): Literal
    {
        $number = '';
        while (true) {
            $char = $chars->peek();
            if ($char === null || !is_numeric($number . $char)) {
                break;
            }
            $number .= $char;
            $chars->next();
            $column++;
        }
        return new Literal(str_contains($number, '.') ? (float)$number : (int)$number);
    }

    /**
     * A string literal is the only token that may contain a real newline, so this is the only scanner that can leave
     * the line it started on. It moves $line as well as $column: an extent that ended on the starting line would be
     * wrong for the literal itself, and every token after it would be reported a line too high.
     *
     * @param Peekable<string> $chars
     * @param positive-int $line
     * @param positive-int $column
     * @return Literal<string>
     */
    private static function string(Peekable $chars, int &$line, int &$column): Literal
    {
        $string = '';
        while (true) {
            $char = $chars->peek();
            if ($char === null) {
                throw SyntaxError::create('Expected closing quote', Span::char($line, $column));
            }
            $chars->next();
            if ($char === '"') {
                $column++;
                break;
            }
            if ($char === "\n") {
                $line++;
                $column = 1;
            } else {
                $column++;
            }
            $string .= $char;
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
