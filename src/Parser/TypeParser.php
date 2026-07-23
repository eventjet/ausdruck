<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Parser;

use function array_merge;
use function assert;
use function is_string;
use function sprintf;
use function str_split;

/**
 * @phpstan-type AnyToken Token | string | Literal<string | int | float>
 * @internal
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
        if ($node instanceof ParsedToken) {
            return SyntaxError::create(sprintf('Expected type, got %s', Token::print($node->token)), $node->location());
        }
        if ($node === null) {
            return SyntaxError::create('Invalid type ""', Span::char(1, 1));
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
        while (($nameToken = $tokens->peek()) !== null) {
            $name = $nameToken->token;
            if (!is_string($name)) {
                throw SyntaxError::create(
                    sprintf('Expected type name, got %s', Token::print($name)),
                    $nameToken->location(),
                );
            }
            $tokens->next();
            self::expect($tokens, Token::Colon);
            $node = self::parse($tokens);
            if ($node === null) {
                throw SyntaxError::create(
                    sprintf('Expected a type for %s, got end of input', $name),
                    $nameToken->location(),
                );
            }
            if (!$node instanceof TypeNode) {
                throw SyntaxError::create(
                    sprintf('Expected a type for %s, got %s', $name, Token::print($node->token)),
                    $node->location(),
                );
            }
            $declarations[$name] = $node;
        }
        return $declarations;
    }

    /**
     * @param Peekable<ParsedToken> $tokens
     */
    public static function parse(Peekable $tokens): TypeNode|ParsedToken|null
    {
        $parsedToken = $tokens->peek();
        if ($parsedToken === null) {
            return null;
        }
        if ($parsedToken->token === Token::OpenBrace) {
            return self::parseStruct($tokens);
        }
        $name = $parsedToken->token;
        if (!is_string($name)) {
            return $parsedToken;
        }
        $tokens->next();
        if ($name === 'fn') {
            return self::parseFunction($tokens, $parsedToken->location());
        }
        if ($tokens->peek()?->token !== Token::OpenAngle) {
            return new TypeNode($name, [], $parsedToken->location());
        }
        // Only a name with a declared arity of its own can commit to reading a type argument list. A name that isn't a
        // built-in constructor has none, and neither has one that is never written `name<...>` in the first place —
        // `fn`, which took its own route above, and a struct, which is written as its fields.
        $arity = TypeConstructor::tryFrom($name)?->typeArgumentCount();
        if ($arity !== null && $arity > 0) {
            // A generic constructor is committed: the `<` can only be its argument list, so whatever goes wrong in
            // there is a genuine error, reported where it happens rather than rewound.
            $tokens->next();
            $args = self::parseTypeList($tokens);
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
        $closeAngle = self::expect($tokens, Token::CloseAngle);
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
            $args = self::parseTypeList($tokens);
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
    private static function parseTypeList(Peekable $tokens): array
    {
        $args = [];
        while (true) {
            $arg = self::parse($tokens);
            if (!$arg instanceof TypeNode) {
                break;
            }
            $args[] = $arg;
            if ($tokens->peek()?->token === Token::Comma) {
                $tokens->next();
            }
        }
        return $args;
    }

    /**
     * @param Peekable<ParsedToken> $tokens
     * @param AnyToken $expected
     */
    private static function expect(Peekable $tokens, Token|string|Literal $expected): ParsedToken
    {
        $actual = $tokens->peek();
        if ($actual === null) {
            $previousToken = $tokens->previous();
            assert($previousToken !== null);
            $span = Span::char($previousToken->line, $previousToken->column + 1);
            throw SyntaxError::create(sprintf('Expected %s, got end of input', Token::print($expected)), $span);
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
     * @param Peekable<ParsedToken> $tokens
     */
    private static function parseFunction(Peekable $tokens, Span $fnLocation): TypeNode
    {
        self::expect($tokens, Token::OpenParen);
        $params = self::parseTypeList($tokens);
        self::expect($tokens, Token::CloseParen);
        $arrow = self::expect($tokens, Token::Arrow);
        $returnType = self::parse($tokens);
        if ($returnType === null) {
            throw SyntaxError::create('Expected return type, got end of input', $arrow->location());
        }
        if ($returnType instanceof ParsedToken) {
            throw SyntaxError::create(
                sprintf('Expected return type, got %s', Token::print($returnType->token)),
                $returnType->location(),
            );
        }
        return new TypeNode('fn', array_merge($params, [$returnType]), $fnLocation->to($returnType->location));
    }

    /**
     * @param Peekable<ParsedToken> $tokens
     */
    private static function parseStruct(Peekable $tokens): TypeNode
    {
        $openBraceToken = $tokens->peek();
        assert($openBraceToken !== null);
        $start = $openBraceToken->location();
        $tokens->next();
        $fields = [];
        while (true) {
            $nameToken = $tokens->peek();
            if ($nameToken === null) {
                break;
            }
            if ($nameToken->token === Token::CloseBrace) {
                break;
            }
            $name = $nameToken->token;
            if (!is_string($name)) {
                throw SyntaxError::create(
                    sprintf('Expected field name, got %s', Token::print($name)),
                    $nameToken->location(),
                );
            }
            $tokens->next();
            self::expect($tokens, Token::Colon);
            $type = self::parse($tokens);
            if ($type === null) {
                throw SyntaxError::create('Expected type, got end of input', $nameToken->location());
            }
            if (!$type instanceof TypeNode) {
                throw SyntaxError::create(
                    sprintf('Expected type, got %s', Token::print($type->token)),
                    $type->location(),
                );
            }
            $fields[] = TypeNode::keyValue(new TypeNode($name, [], $nameToken->location()), $type);
            $token = $tokens->peek();
            if ($token?->token !== Token::Comma) {
                break;
            }
            $tokens->next();
        }
        $end = self::expect($tokens, Token::CloseBrace)->location();
        return TypeNode::struct($fields, $start->to($end));
    }
}
