<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Parser;

use function is_string;
use function sprintf;

/**
 * The reading conventions {@see ExpressionParser} and {@see TypeParser} share: what it means to require a token, where
 * the input ran out, and the shape of every list in the language. Both walk the same token stream, so both had private
 * copies of these; one copy is what keeps the two from drifting apart as either is edited.
 *
 * @internal
 * @psalm-internal Eventjet\Ausdruck\Parser
 */
final class Tokens
{
    /**
     * @param Peekable<ParsedToken> $tokens
     */
    public static function expect(Peekable $tokens, Token $expected): ParsedToken
    {
        $actual = $tokens->peek();
        if ($actual === null) {
            throw SyntaxError::create(
                sprintf('Expected %s, got end of input', Token::print($expected)),
                self::endOfInput($tokens),
            );
        }
        if ($actual->token === $expected) {
            $tokens->next();
            return $actual;
        }
        throw SyntaxError::create(
            sprintf('Expected %s, got %s', Token::print($expected), Token::print($actual->token)),
            $actual->location(),
        );
    }

    /**
     * @param string $expected What to call the identifier in the error, e.g. "field name".
     * @param Peekable<ParsedToken> $tokens
     * @return array{string, Span}
     */
    public static function expectIdentifier(Peekable $tokens, string $expected): array
    {
        $name = $tokens->peek();
        if ($name === null) {
            throw SyntaxError::create(
                sprintf('Expected %s, got end of input', $expected),
                self::endOfInput($tokens),
            );
        }
        if (!is_string($name->token)) {
            throw SyntaxError::create(
                sprintf('Expected %s, got %s', $expected, Token::print($name->token)),
                $name->location(),
            );
        }
        $tokens->next();
        return [$name->token, $name->location()];
    }

    /**
     * The body of every bracketed, comma-separated list in the language: type arguments, function parameter types,
     * struct fields—both the type and the literal—call arguments, list items and lambda parameters.
     *
     * A trailing comma is allowed. A missing one simply ends the list, which leaves the caller's expect($close) to
     * report the token that isn't a comma. Ending on the missing comma rather than on something that can't start
     * another element is what lets an unclosed list be blamed on the bracket it needs instead of on whatever follows:
     * `fn(int -> string` asks for the `)`, because the list stopped at a token that wasn't a comma without ever having
     * to decide whether `->` could begin a parameter. Nothing here knows what an element looks like, so no element can
     * be added that this has to be taught about.
     *
     * @template T
     * @param Peekable<ParsedToken> $tokens
     * @param callable(): T $parseItem
     * @return list<T>
     */
    public static function commaSeparated(Peekable $tokens, Token $close, callable $parseItem): array
    {
        $items = [];
        while (true) {
            $token = $tokens->peek()?->token;
            if ($token === null || $token === $close) {
                return $items;
            }
            $items[] = $parseItem();
            if ($tokens->peek()?->token !== Token::Comma) {
                return $items;
            }
            $tokens->next();
        }
    }

    /**
     * Where the input ran out: just after the last token read, or the very start of it if there never was one.
     *
     * @param Peekable<ParsedToken> $tokens
     */
    public static function endOfInput(Peekable $tokens): Span
    {
        $previous = $tokens->previous();
        return $previous === null ? Span::char(1, 1) : Span::char($previous->line, $previous->column + 1);
    }
}
