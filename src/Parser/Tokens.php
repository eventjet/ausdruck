<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Parser;

use function is_string;
use function sprintf;
use function str_split;

/**
 * The stream of tokens a parser reads, and the reading conventions {@see ExpressionParser} and {@see TypeParser} share:
 * what it means to require something, where the input ran out, and the shape of every list in the language. Both walk
 * the same stream, so both had private copies of these; one copy is what keeps the two from drifting apart as either is
 * edited.
 *
 * Requiring something is stated once by {@see self::expected()} and not just for tokens: a type and an expression are
 * required in the same words and blamed on the same span as a closing bracket, so the two parsers cannot word or locate
 * the same complaint differently.
 *
 * The cursor underneath is a {@see Peekable}, which buffers whatever it is given and knows nothing about tokens—the
 * tokenizer reads characters through one of its own. Everything token-shaped lives here instead, so a parser holds a
 * stream that is already of tokens rather than one it has to keep saying is. It also only holds the part of the cursor
 * a parser has any business with: looking behind is needed to say where the input ran out and nowhere else, so
 * {@see Peekable::previous()} is not forwarded and {@see self::endOfInput()} is the one place that calls it.
 *
 * @internal
 * @psalm-internal Eventjet\Ausdruck\Parser
 */
final class Tokens
{
    /**
     * @param Peekable<ParsedToken> $tokens
     */
    private function __construct(private readonly Peekable $tokens)
    {
    }

    public static function of(string $source): self
    {
        /**
         * @infection-ignore-all Currently, there's no difference between str_split and its multibyte version. Multibyte
         *     string literals and identifiers are just put back together. If you encounter a case where it does matter,
         *     just change it to mb_str_split and add an appropriate test case.
         */
        $chars = $source === '' ? [] : str_split($source);
        return new self(new Peekable(Tokenizer::tokenize($chars)));
    }

    /**
     * The token the parser is looking at, or null at the end of the input. Impure for the reason
     * {@see Peekable::peek()} is: it is the read that scans the token.
     *
     * @phpstan-impure
     */
    public function peek(): ParsedToken|null
    {
        return $this->tokens->peek();
    }

    /**
     * Just which token it is, for deciding what to parse next: that decision never depends on where the token is, and
     * asking for only what it turns on keeps the location out of the conditions.
     *
     * @return Token | string | Literal<string | int | float | bool> | null
     * @phpstan-impure
     */
    public function peekToken(): Token|string|Literal|null
    {
        return $this->tokens->peek()?->token;
    }

    /**
     * Advances past the token the parser is looking at. Nothing is returned: every caller has already peeked the token
     * it is stepping over, so handing it back again would only be a second way to say what it is.
     */
    public function next(): void
    {
        $this->tokens->next();
    }

    /**
     * The current position, to be handed back to {@see self::restore()}.
     *
     * @return non-negative-int
     */
    public function snapshot(): int
    {
        return $this->tokens->snapshot();
    }

    /**
     * @param non-negative-int $snapshot
     */
    public function restore(int $snapshot): void
    {
        $this->tokens->restore($snapshot);
    }

    /**
     * Something was required here and isn't there. One rule with one span policy—blame the token that turned up, or
     * where the input ran out if none did—so every reading that requires something says so through this rather than
     * wording and locating its own complaint. What is required needn't be a single token: `type` and `expression` are
     * asked for the same way `)` is, and are named the same way in the error.
     *
     * @param string $what What was required, as the error should name it: a printed token, or a phrase like
     *     "field name" or "return type".
     */
    public function expected(string $what): SyntaxError
    {
        $actual = $this->tokens->peek();
        return SyntaxError::create(
            $actual === null
                ? sprintf('Expected %s, got end of input', $what)
                : sprintf('Expected %s, got %s', $what, Token::print($actual->token)),
            $actual?->location() ?? $this->endOfInput(),
        );
    }

    public function expect(Token $expected): ParsedToken
    {
        $actual = $this->tokens->peek();
        if ($actual === null || $actual->token !== $expected) {
            throw $this->expected(Token::print($expected));
        }
        $this->tokens->next();
        return $actual;
    }

    /**
     * @param string $expected What to call the identifier in the error, e.g. "field name".
     * @return array{string, Span}
     */
    public function expectIdentifier(string $expected): array
    {
        $name = $this->tokens->peek();
        if ($name === null || !is_string($name->token)) {
            throw $this->expected($expected);
        }
        $this->tokens->next();
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
     * That holds from the second element on. The first has no comma before it to be missing, so a list that is neither
     * empty nor already closed has no choice but to try to read one, and whatever $parseItem makes of the tokens there
     * is what gets reported: `list<:` is blamed on the `:` that can't start a type, not on the `>` it is also missing.
     *
     * The loop ends because $parseItem either consumes a token or throws: a reading that could return having consumed
     * nothing would leave the stream where it was, and the same token would be read as an element forever.
     *
     * @template T
     * @param Token $close The bracket that ends the list, which the caller takes.
     * @param callable(): T $parseItem
     * @return list<T>
     */
    public function commaSeparated(Token $close, callable $parseItem): array
    {
        $items = [];
        while (true) {
            $token = $this->peekToken();
            if ($token === null || $token === $close) {
                return $items;
            }
            $items[] = $parseItem();
            if ($this->peekToken() !== Token::Comma) {
                return $items;
            }
            $this->tokens->next();
        }
    }

    /**
     * Where the input ran out: just after the last token read, or the very start of it if there never was one. Just
     * after the token, that is, not just after the column it starts in—an input ending in `->` ran out two columns on,
     * not one—which is the extent {@see ParsedToken::location()} carries. Carries rather than computes: the width of
     * a token cannot be recovered from the token, only from the source, so an input ending in `1.50` ran out four
     * columns on even though the literal prints back three wide.
     */
    public function endOfInput(): Span
    {
        $previous = $this->tokens->previous();
        if ($previous === null) {
            return Span::char(1, 1);
        }
        $end = $previous->location();
        return Span::char($end->endLine, $end->endColumn + 1);
    }
}
