<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Parser;

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
     * Every branch reads the same way: note where the token starts, scan it, then ask the source how far it got. No
     * scanner keeps a line or column of its own, so none of them can disagree about what reading a character does—see
     * {@see Source}.
     *
     * @param iterable<mixed, string> $chars
     * @return iterable<ParsedToken>
     */
    public static function tokenize(iterable $chars): iterable
    {
        $source = new Source($chars);
        while (true) {
            $char = $source->peek();
            if ($char === null) {
                break;
            }
            if (ctype_space($char)) {
                $source->take();
                continue;
            }
            $start = $source->position();
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
                $source->take();
                yield new ParsedToken($singleCharToken, $source->spanFrom($start));
                continue;
            }
            if ($char === '"') {
                $source->take();
                $string = self::string($source);
                yield new ParsedToken($string, $source->spanFrom($start));
                continue;
            }
            $multiCharToken = match ($char) {
                '=' => self::exact($source, '===', Token::TripleEquals),
                '!' => self::exact($source, '!==', Token::NotEquals),
                '&' => self::exact($source, '&&', Token::And),
                '<' => self::angle($source, Token::OpenAngle, Token::LessThanEquals),
                '>' => self::angle($source, Token::CloseAngle, Token::GreaterThanEquals),
                /**
                 * The sign is never folded into a number literal: a `-` always yields Token::Minus, and
                 * {@see \Eventjet\Ausdruck\Expr::negative()} turns a negated number literal back into a negative one.
                 * If the sign were folded in here, whitespace would silently decide the meaning of `a -2`:
                 * subtraction, or `a` followed by the literal -2.
                 */
                '-' => self::bareOrPair($source, '>', Token::Minus, Token::Arrow),
                '|' => self::bareOrPair($source, '|', Token::Pipe, Token::Or),
                default => null,
            };
            if ($multiCharToken !== null) {
                yield new ParsedToken($multiCharToken, $source->spanFrom($start));
                continue;
            }
            if (is_numeric($char)) {
                $number = self::number($source);
                yield new ParsedToken($number, $source->spanFrom($start));
                continue;
            }
            if (self::isIdentifierChar($char, first: true)) {
                $identifier = self::identifier($source);
                yield new ParsedToken($identifier, $source->spanFrom($start));
                continue;
            }
            throw SyntaxError::create(sprintf('Unexpected character %s', $char), $start->span());
        }
    }

    private static function identifier(Source $source): string
    {
        $identifier = '';

        while (true) {
            $char = $source->peek();

            if ($char === null) {
                break;
            }

            // No idea why it works if "first" is always false, but it
            // does, The error is probably caught somewhere else.
            if (ctype_space($char) || !self::isIdentifierChar($char, first: false)) {
                break;
            }

            $identifier .= $char;
            $source->take();
        }

        return $identifier;
    }

    /**
     * Scans an operator that is the only token starting with its first character: `===`, `!==`, and `&&`. Once that
     * first character is there, the whole sequence is required; anything short of it is an error naming the operator
     * that was expected and underlining the characters that were actually read.
     *
     * @param non-empty-string $sequence
     */
    private static function exact(Source $source, string $sequence, Token $token): Token
    {
        $start = $source->position();
        $read = '';
        foreach (str_split($sequence) as $char) {
            if ($source->peek() !== $char) {
                // The main loop only dispatches here after peeking $sequence's first character, so that one matched
                // and was taken: the span always covers at least it.
                throw SyntaxError::create(
                    sprintf('Expected %s, got %s', $sequence, $read),
                    $source->spanFrom($start),
                );
            }
            $read .= $char;
            $source->take();
        }
        return $token;
    }

    /**
     * `<` and `>` each stand for two things: on their own they are the angle brackets of a generic type (which the
     * expression parser also reads as less-than and greater-than), and followed by `=` they are the comparison
     * operators `<=` and `>=`. The pair reading wins with one exception: two `=` after the angle mean a `===` follows,
     * as in `foo:list<int>===bar`, so the angle stays bare instead of stealing the first `=` and leaving behind a `==`
     * that is no token at all.
     */
    private static function angle(Source $source, Token $bare, Token $pair): Token
    {
        $source->take();
        if ($source->peek() !== '=' || $source->peek(1) === '=') {
            return $bare;
        }
        $source->take();
        return $pair;
    }

    /**
     * A character that means one token on its own and another when $second completes it: `-` and `->`, `|` and `||`.
     * The pair always wins. For `->` it's the only reading that can be meant: no expression continues with a bare `-`
     * followed by a `>`. For `||` it's a choice: the two bars could also be the empty parameter list of a lambda, so
     * that list is written `| |`, and glued bars are the or they almost always are.
     *
     * @param non-empty-string $second The character that, if it comes next, makes this $pair instead of $bare.
     */
    private static function bareOrPair(Source $source, string $second, Token $bare, Token $pair): Token
    {
        $source->take();
        if ($source->peek() !== $second) {
            return $bare;
        }
        $source->take();
        return $pair;
    }

    /**
     * @return Literal<int | float>
     */
    private static function number(Source $source): Literal
    {
        $number = '';
        while (true) {
            $char = $source->peek();
            if ($char === null || !is_numeric($number . $char)) {
                break;
            }
            $number .= $char;
            $source->take();
        }
        return new Literal(str_contains($number, '.') ? (float)$number : (int)$number);
    }

    /**
     * A string literal is the only token that may contain a real newline. Nothing here says so: the source counts
     * lines as it is read, so a literal that spans several of them ends where it ends, and every token after it is
     * still reported on the line it is written on.
     *
     * @return Literal<string>
     */
    private static function string(Source $source): Literal
    {
        $string = '';
        while (true) {
            $char = $source->take();
            if ($char === null) {
                throw SyntaxError::create('Expected closing quote', $source->position()->span());
            }
            if ($char === '"') {
                break;
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
