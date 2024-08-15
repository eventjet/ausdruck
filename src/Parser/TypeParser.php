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
 * @psalm-internal Eventjet\Ausdruck\Parser
 */
final class TypeParser
{
    public static function parseString(string $str): TypeNode|SyntaxError
    {
        $chars = $str === '' ? [] : str_split($str);
        try {
            $node = self::parse(new Peekable(Tokenizer::tokenize($chars)));
        } catch (SyntaxError $e) {
            return $e;
        }
        if ($node instanceof ParsedToken) {
            return SyntaxError::create(sprintf('Expected type, got %s', Token::print($node->token)), $node->location());
        }
        if ($node === null) {
            return SyntaxError::create('Invalid type ""', Span::char(1, 1));
        }
        return $node;
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
            return self::parseStruct($tokens, $parsedToken->location());
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
        $tokens->next();
        $args = self::parseTypeList($tokens);
        $closeAngle = self::expect($tokens, Token::CloseAngle);
        return new TypeNode($name, $args, $parsedToken->location()->to($closeAngle->location()));
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
    private static function parseStruct(Peekable $tokens, Span $location): TypeNode
    {
        $openBraceToken = $tokens->peek();
        assert($openBraceToken !== null);
        $openBrace = $openBraceToken?->location();
        assert($openBrace !== null);
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
        $closeBrace = self::expect($tokens, Token::CloseBrace)->location();
        return TypeNode::struct($fields, $location->to($closeBrace));
    }
}
