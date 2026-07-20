<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Parser;

use function array_merge;
use function is_string;
use function sprintf;
use function str_split;

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
        $chars = $str === '' ? [] : str_split($str);
        $tokens = new Peekable(Tokenizer::tokenize($chars));
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
        $tokens = new Peekable(Tokenizer::tokenize($src === '' ? [] : str_split($src)));
        $declarations = [];
        while ($tokens->peek() !== null) {
            [$name] = Tokens::expectIdentifier($tokens, 'type name');
            Tokens::expect($tokens, Token::Colon);
            $declarations[$name] = self::parse($tokens);
        }
        return $declarations;
    }

    /**
     * Reads one type off the stream. Everywhere a type may appear, one is required, so whether the tokens ahead are a
     * type at all is settled here rather than handed back for each call site to decode and word its own complaint
     * about. Lists of types don't have to ask either: {@see Tokens::commaSeparated()} ends on a missing comma, not on
     * a token that can't start an element, so a type form added here needs no telling anywhere else.
     *
     * @param Peekable<ParsedToken> $tokens
     */
    public static function parse(Peekable $tokens): TypeNode
    {
        $parsedToken = $tokens->peek();
        if ($parsedToken === null) {
            throw SyntaxError::create('Expected type, got end of input', Tokens::endOfInput($tokens));
        }
        if ($parsedToken->token === Token::OpenBrace) {
            return self::parseStruct($tokens, $parsedToken->location());
        }
        $name = $parsedToken->token;
        if (!is_string($name)) {
            throw SyntaxError::create(
                sprintf('Expected type, got %s', Token::print($name)),
                $parsedToken->location(),
            );
        }
        $tokens->next();
        if ($name === 'fn') {
            return self::parseFunction($tokens, $parsedToken->location());
        }
        if ($tokens->peek()?->token !== Token::OpenAngle) {
            return new TypeNode($name, [], $parsedToken->location());
        }
        if (TypeConstructor::tryFrom($name)?->takesTypeArguments() ?? false) {
            // A generic constructor is committed: the `<` can only be its argument list, so whatever goes wrong in
            // there is a genuine error, reported where it happens rather than rewound.
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
        $closeAngle = Tokens::expect($tokens, Token::CloseAngle);
        return new TypeNode($name, $args, $parsedToken->location()->to($closeAngle->location()));
    }

    /**
     * Reads a `<...>` type argument list, stopping on its `>` so the caller can take it. Returns null—leaving the
     * stream exactly where it was—if what follows isn't one after all. A {@see SyntaxError} raised along the way is
     * not an error to report: it only means these tokens aren't a type argument list either, and the caller is one
     * that has some other reading of the `<` to fall back on. Anything already committed to a type argument list goes
     * the direct route and lets its errors out.
     *
     * @param Peekable<ParsedToken> $tokens
     * @return list<TypeNode> | null
     */
    private static function tryTypeArguments(Peekable $tokens): array|null
    {
        $snapshot = $tokens->snapshot();
        try {
            $tokens->next();
            $args = self::parseTypeList($tokens, Token::CloseAngle);
            if ($tokens->peek()?->token === Token::CloseAngle) {
                return $args;
            }
        } catch (SyntaxError) {
        }
        $tokens->restore($snapshot);
        return null;
    }

    /**
     * map<int, string>
     *     ===========
     *
     * @param Peekable<ParsedToken> $tokens
     * @return list<TypeNode>
     */
    private static function parseTypeList(Peekable $tokens, Token $close): array
    {
        return Tokens::commaSeparated($tokens, $close, static fn(): TypeNode => self::parse($tokens));
    }

    /**
     * @param Peekable<ParsedToken> $tokens
     */
    private static function parseFunction(Peekable $tokens, Span $fnLocation): TypeNode
    {
        Tokens::expect($tokens, Token::OpenParen);
        $params = self::parseTypeList($tokens, Token::CloseParen);
        Tokens::expect($tokens, Token::CloseParen);
        Tokens::expect($tokens, Token::Arrow);
        $returnType = self::parse($tokens);
        return new TypeNode('fn', array_merge($params, [$returnType]), $fnLocation->to($returnType->location));
    }

    /**
     * @param Peekable<ParsedToken> $tokens
     * @param Span $start The location of the `{`, which {@see self::parse()} has already peeked.
     */
    private static function parseStruct(Peekable $tokens, Span $start): TypeNode
    {
        $tokens->next();
        $fields = Tokens::commaSeparated(
            $tokens,
            Token::CloseBrace,
            static fn(): TypeNode => self::parseField($tokens),
        );
        $end = Tokens::expect($tokens, Token::CloseBrace)->location();
        return TypeNode::struct($fields, $start->to($end));
    }

    /**
     * {name: string, age: int}
     *  ============
     *
     * @param Peekable<ParsedToken> $tokens
     */
    private static function parseField(Peekable $tokens): TypeNode
    {
        [$name, $nameLocation] = Tokens::expectIdentifier($tokens, 'field name');
        Tokens::expect($tokens, Token::Colon);
        return TypeNode::keyValue(new TypeNode($name, [], $nameLocation), self::parse($tokens));
    }
}
