<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Parser;

use function array_merge;
use function is_string;
use function sprintf;

/**
 * @psalm-internal Eventjet\Ausdruck\Parser
 */
final class TypeParser
{
    /**
     * A whole string is a whole type: unlike {@see self::parse()}, which reads one type off a stream the expression
     * parser keeps using afterwards, nothing here comes after the type, so a leftover token is an error rather than the
     * caller's business.
     */
    public static function parseString(string $str): TypeNode|SyntaxError
    {
        $tokens = Tokens::of($str);
        try {
            $node = self::parse($tokens);
        } catch (SyntaxError $e) {
            return $e;
        }
        $trailing = $tokens->peek();
        if ($trailing !== null) {
            return SyntaxError::unexpectedToken($trailing);
        }
        return $node;
    }

    /**
     * A sequence of `Name: <type>` declarations. A type ends where it is complete, so the next declaration's name is
     * simply the next token; no separator is needed between them.
     *
     * @return array<string, TypeNode>
     */
    public static function parseDeclarations(string $src): array
    {
        $tokens = Tokens::of($src);
        $declarations = [];
        while ($tokens->peek() !== null) {
            [$name] = $tokens->expectIdentifier('type name');
            $tokens->expect(Token::Colon);
            // Named, because a declaration string holds several and the span alone leaves the reader counting them.
            $declarations[$name] = self::parse($tokens, sprintf('type for %s', $name));
        }
        return $declarations;
    }

    /**
     * Reads one type off the stream. Everywhere a type may appear, one is required, so whether the tokens ahead are a
     * type at all is settled here rather than handed back for each call site to decode and word its own complaint
     * about. Lists of types don't have to ask either: {@see Tokens::commaSeparated()} ends on a missing comma, not on
     * a token that can't start an element, so a type form added here needs no telling anywhere else.
     *
     * @param string $expected What to call the type in the error, e.g. "return type"—the same courtesy
     *     {@see Tokens::expectIdentifier()} extends to names. A call site with nothing more specific to say than
     *     "type" says nothing.
     */
    public static function parse(Tokens $tokens, string $expected = 'type'): TypeNode
    {
        $parsedToken = $tokens->peek();
        if ($parsedToken === null) {
            throw SyntaxError::create(
                sprintf('Expected %s, got end of input', $expected),
                $tokens->endOfInput(),
            );
        }
        if ($parsedToken->token === Token::OpenBrace) {
            return self::parseStruct($tokens, $parsedToken->location());
        }
        $name = $parsedToken->token;
        if (!is_string($name)) {
            throw SyntaxError::create(
                sprintf('Expected %s, got %s', $expected, Token::print($name)),
                $parsedToken->location(),
            );
        }
        $tokens->next();
        if ($name === 'fn') {
            return self::parseFunction($tokens, $parsedToken->location());
        }
        if ($tokens->peekToken() !== Token::OpenAngle) {
            return new TypeNode($name, [], $parsedToken->location());
        }
        if (TypeConstructor::tryFrom($name)?->takesTypeArguments() ?? false) {
            // A generic constructor is committed: the `<` can only be its argument list, so whatever goes wrong in
            // there is a genuine error, reported where it happens rather than rewound—unless a speculative list
            // further out is still deciding and swallows it. See self::tryTypeArguments().
            $tokens->next();
            $args = self::parseTypeList($tokens, Token::CloseAngle);
        } else {
            // Any other name might be an operand rather than a type constructor, and the `<` a less-than: `a:int <
            // b:int` is a comparison. Only a list that parses and closes is a type argument list, so try to read one
            // and hand the `<` back if that isn't what's there. A closed list after a name that takes none
            // (`int<string>`) is read as a type on purpose, so the resolver can reject it by name.
            $args = self::tryTypeArguments($tokens);
            if ($args === null) {
                return new TypeNode($name, [], $parsedToken->location());
            }
        }
        $closeAngle = $tokens->expect(Token::CloseAngle);
        return new TypeNode($name, $args, $parsedToken->location()->to($closeAngle->location()));
    }

    /**
     * Reads a `<...>` type argument list, stopping on its `>` so the caller can take it. Returns null—leaving the
     * stream exactly where it was—if what follows isn't one after all. A {@see SyntaxError} raised along the way is
     * not an error to report: it only means these tokens aren't a type argument list either, and the caller is one
     * that has some other reading of the `<` to fall back on.
     *
     * That goes for an error from a committed constructor nested inside, too. `MyType<list<int int>>` is blamed on its
     * outer `<`, not on the comma missing from the inner list, because the reading fallen back to is a less-than, and
     * it is `MyType < list<int int>>` that then has to make something of the tokens after it. Letting the innermost
     * error out instead would be wrong wherever the fallback is what the writer meant: `a:MyType < list<int>` is a
     * comparison, and the list in it closes only because the reading that rewound stopped looking. Telling those two
     * apart means keeping the furthest failure across every reading tried and reporting that one once they have all
     * failed—a way of choosing between errors this parser doesn't have.
     *
     * @return list<TypeNode> | null
     */
    private static function tryTypeArguments(Tokens $tokens): array|null
    {
        $snapshot = $tokens->snapshot();
        try {
            $tokens->next();
            $args = self::parseTypeList($tokens, Token::CloseAngle);
            if ($tokens->peekToken() === Token::CloseAngle) {
                return $args;
            }
        } catch (SyntaxError) {
        }
        $tokens->restore($snapshot);
        return null;
    }

    /**
     * The types inside a pair of brackets, wherever a type is written with several:
     *
     *     map<int, string>        fn(int, string) -> bool
     *         ===========            ===========
     *
     * @param Token $close The bracket that ends the list, which the caller takes.
     * @return list<TypeNode>
     */
    private static function parseTypeList(Tokens $tokens, Token $close): array
    {
        return $tokens->commaSeparated($close, static fn(): TypeNode => self::parse($tokens));
    }

    private static function parseFunction(Tokens $tokens, Span $fnLocation): TypeNode
    {
        $tokens->expect(Token::OpenParen);
        $params = self::parseTypeList($tokens, Token::CloseParen);
        $tokens->expect(Token::CloseParen);
        $tokens->expect(Token::Arrow);
        $returnType = self::parse($tokens, 'return type');
        return new TypeNode('fn', array_merge($params, [$returnType]), $fnLocation->to($returnType->location));
    }

    /**
     * @param Span $start The location of the `{`, which {@see self::parse()} has already peeked.
     */
    private static function parseStruct(Tokens $tokens, Span $start): TypeNode
    {
        $tokens->next();
        $fields = $tokens->commaSeparated(Token::CloseBrace, static fn(): TypeNode => self::parseField($tokens));
        $end = $tokens->expect(Token::CloseBrace)->location();
        return TypeNode::struct($fields, $start->to($end));
    }

    /**
     * {name: string, age: int}
     *  ============
     */
    private static function parseField(Tokens $tokens): TypeNode
    {
        [$name, $nameLocation] = $tokens->expectIdentifier('field name');
        $tokens->expect(Token::Colon);
        return TypeNode::keyValue(new TypeNode($name, [], $nameLocation), self::parse($tokens));
    }
}
