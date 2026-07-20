<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Parser;

use function array_merge;
use function assert;
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
            $declarations[$name] = self::parse($tokens);
        }
        return $declarations;
    }

    /**
     * Reads one type off the stream. Everywhere a type may appear, one is required, so whether the tokens ahead are a
     * type at all is settled here rather than handed back for each call site to decode and word its own complaint
     * about. The one place that has to ask is {@see self::parseTypeList()}, which reads its elements with
     * {@see self::tryParse()} so that it stops where types stop.
     *
     * @param Peekable<ParsedToken> $tokens
     */
    public static function parse(Peekable $tokens): TypeNode
    {
        return self::tryParse($tokens) ?? throw self::expectedType($tokens);
    }

    /**
     * One type, or null if none begins here. Whether a type begins here is decided before anything is read, so a null
     * return leaves the stream exactly where it was; once a type has begun, going wrong in the middle of it throws.
     * Knowing what can start a type is only this method's business—asking is {@see self::parse()}'s `?? throw` and
     * {@see self::parseTypeList()}'s loop condition, neither of which repeats the answer.
     *
     * @param Peekable<ParsedToken> $tokens
     */
    private static function tryParse(Peekable $tokens): TypeNode|null
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
            return null;
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
     * The complaint for tokens that don't begin a type, worded once for every place a type is required.
     *
     * @param Peekable<ParsedToken> $tokens
     */
    private static function expectedType(Peekable $tokens): SyntaxError
    {
        $next = $tokens->peek();
        return $next === null
            ? SyntaxError::create('Expected type, got end of input', self::endOfInput($tokens))
            : SyntaxError::create(
                sprintf('Expected type, got %s', Token::print($next->token)),
                $next->location(),
            );
    }

    /**
     * map<int, string>
     *     ===========
     *
     * The list ends where types stop—at the closing bracket, at the end of the input, or at anything else that can't
     * begin one. Whatever that turns out to be is left for the caller to {@see self::expect()}, so an unclosed list is
     * reported as the bracket it is missing rather than as a list element that doesn't parse.
     *
     * @param Peekable<ParsedToken> $tokens
     * @return list<TypeNode>
     */
    private static function parseTypeList(Peekable $tokens): array
    {
        $args = [];
        while (($arg = self::tryParse($tokens)) !== null) {
            $args[] = $arg;
            if ($tokens->peek()?->token === Token::Comma) {
                $tokens->next();
            }
        }
        return $args;
    }

    /**
     * @param Peekable<ParsedToken> $tokens
     */
    private static function expect(Peekable $tokens, Token $expected): ParsedToken
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
     * Where the input ran out: just after the last token read, or the very start of it if there never was one.
     *
     * @param Peekable<ParsedToken> $tokens
     */
    private static function endOfInput(Peekable $tokens): Span
    {
        $previous = $tokens->previous();
        return $previous === null ? Span::char(1, 1) : Span::char($previous->line, $previous->column + 1);
    }

    /**
     * @param Peekable<ParsedToken> $tokens
     */
    private static function parseFunction(Peekable $tokens, Span $fnLocation): TypeNode
    {
        self::expect($tokens, Token::OpenParen);
        $params = self::parseTypeList($tokens);
        self::expect($tokens, Token::CloseParen);
        self::expect($tokens, Token::Arrow);
        $returnType = self::parse($tokens);
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
