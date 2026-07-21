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
     * Every character that begins an operator is listed once, in the match below, and its arm answers which operator it
     * begins: the token itself for a character that is a whole operator on its own, {@see self::pair()} for one that
     * may be completed by a second, {@see self::angle()} for the two that may be and mean something else when they
     * aren't, and {@see self::exact()} for one that begins a token and nothing else. Giving `+` a longer reading later
     * means changing what its arm answers, not moving the arm somewhere a different rule applies.
     *
     * The arms only look ahead; naming the token is all they do. Scanning past it is the loop's job below, and it needs
     * nothing but the answer: every {@see Token}'s value is the source text that spells it, so the operator is as many
     * characters long as its own name, wherever the arm that named it looked.
     *
     * Everything below that starts with a character no operator can: whitespace, a quote, a digit, or the start of an
     * identifier. Those are told apart by asking, because they are classes of character rather than single ones.
     *
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
            $startCol = $column;
            $operator = match ($char) {
                '.' => Token::Dot,
                '(' => Token::OpenParen,
                ')' => Token::CloseParen,
                ':' => Token::Colon,
                ',' => Token::Comma,
                '+' => Token::Plus,
                '*' => Token::Asterisk,
                '/' => Token::Slash,
                '%' => Token::Percent,
                '[' => Token::OpenBracket,
                ']' => Token::CloseBracket,
                '{' => Token::OpenBrace,
                '}' => Token::CloseBrace,
                '=' => self::exact($chars, $line, $column, Token::TripleEquals),
                // `!` on its own is Token::Not; followed by `=` it commits to its pair, `!==`, after the same one
                // character of lookahead as {@see self::pair()}'s callers below—but that pair is two characters long,
                // more than a lookahead can settle, so it reads {@see self::exact()} for the rest instead of
                // returning a token bare.
                '!' => $chars->peek(1) === '=' ? self::exact($chars, $line, $column, Token::NotEquals) : Token::Not,
                '&' => self::exact($chars, $line, $column, Token::And),
                '<' => self::angle($chars, Token::OpenAngle, Token::LessThanEquals),
                '>' => self::angle($chars, Token::CloseAngle, Token::GreaterThanEquals),
                // A `-` always yields Token::Minus, never a sign folded into the number literal that follows it:
                // {@see \Eventjet\Ausdruck\Expr::negative()} does that folding once the minus is known to be a
                // negation. Doing it here would let whitespace silently decide what `a -2` means.
                '-' => self::pair($chars, '>', Token::Minus, Token::Arrow),
                '|' => self::pair($chars, '|', Token::Pipe, Token::Or),
                default => null,
            };
            if ($operator !== null) {
                // Walking off what the arm named. The assert is the rule above, checked where it is relied on: a
                // token whose value is not the text that spells it would leave the cursor past input it never read.
                foreach (str_split($operator->value) as $expected) {
                    assert($chars->peek() === $expected);
                    $chars->next();
                    $column++;
                }
                yield new ParsedToken($operator, $line, $startCol);
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
                $chars->next();
                $column++;
                yield new ParsedToken(self::string($chars, $line, $column), $line, $startCol);
                continue;
            }
            if (is_numeric($char)) {
                yield new ParsedToken(self::number($chars, $column), $line, $startCol);
                continue;
            }
            if (self::isIdentifierChar($char, first: true)) {
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
     * Answers the operator that is the only token the reading can still be: `===` and `&&` from their first character,
     * `!==` from the peek at its second that the `!` arm has already committed to. Once that much is there, the whole
     * sequence is required; anything short of it is an error naming the operator that was expected and underlining the
     * characters that were actually read. The sequence is the token's own spelling, so the operator the error names is
     * the one it was scanning for, and $ahead—how far into that spelling the reading got—is at once how far to peek,
     * how much was read, and where the underline ends.
     *
     * @param Peekable<string> $chars
     * @param positive-int $line
     * @param positive-int $column Where the first character is. Nothing here moves the cursor; the caller does that.
     */
    private static function exact(Peekable $chars, int $line, int $column, Token $token): Token
    {
        $read = '';
        foreach (str_split($token->value) as $ahead => $char) {
            if ($chars->peek($ahead) !== $char) {
                $endColumn = $column + $ahead - 1;
                // Every caller has peeked before dispatching here: the main loop the token's first character, and the
                // `!` arm its second as well. So the mismatch is never on the first character, and $ahead is at
                // least 1.
                assert($endColumn >= 1);
                throw SyntaxError::create(
                    sprintf('Expected %s, got %s', $token->value, $read),
                    new Span($line, $column, $line, $endColumn),
                );
            }
            $read .= $char;
        }
        return $token;
    }

    /**
     * `<` and `>` each stand for two things: on their own they are the angle brackets of a generic type (which the
     * expression parser also reads as less-than and greater-than), and followed by `=` they are the comparison
     * operators `<=` and `>=`. That is {@see self::pair()}'s rule, with one exception: two `=` after the angle mean a
     * `===` follows, as in `foo:list<int>===bar`, so the angle stays bare instead of stealing the first `=` and leaving
     * behind a `==` that is no token at all.
     *
     * @param Peekable<string> $chars
     */
    private static function angle(Peekable $chars, Token $bare, Token $pair): Token
    {
        return $chars->peek(1) === '=' && $chars->peek(2) === '='
            ? $bare
            : self::pair($chars, '=', $bare, $pair);
    }

    /**
     * A character that means one token on its own and another when $second completes it: `-` and `->`, `|` and `||`.
     * The pair wins where it can. For `->` it's the only reading that can be meant: no expression continues with a bare
     * `-` followed by a `>`. For `||` it's a choice: the two bars could also be the empty parameter list of a lambda,
     * so that list is written `| |`, and glued bars are the or they almost always are.
     *
     * @param Peekable<string> $chars
     * @param non-empty-string $second The character that, if it comes next, makes this $pair instead of $bare.
     */
    private static function pair(Peekable $chars, string $second, Token $bare, Token $pair): Token
    {
        return $chars->peek(1) === $second ? $pair : $bare;
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
