<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Parser;

use Eventjet\Ausdruck\Call;
use Eventjet\Ausdruck\Expr;
use Eventjet\Ausdruck\Expression;
use Eventjet\Ausdruck\Get;
use Eventjet\Ausdruck\Scope;
use Eventjet\Ausdruck\Type;
use function array_key_exists;
use function assert;
use function is_string;
use function sprintf;
use function str_split;

/**
 * @phpstan-type AnyToken Token | string | Literal<string | int | float>
 * @phpstan-type Declarations array<string, Declaration>
 * @api
 */
final class ExpressionParser
{
    /**
     * @return Expression<mixed>
     */
    public static function parse(string $expression, Types|null $types = null): Expression
    {
        $chars = $expression === '' ? [] : str_split($expression);
        /**
         * @infection-ignore-all Currently, there's no difference between str_split and its multibyte version. Multibyte
         *     string literals and identifiers are just put back together. If you encounter a case where it does matter,
         *     just change it to mb_str_split and add an appropriate test case.
         */
        return self::parseExpression(new Peekable(Tokenizer::tokenize($chars)), $types ?? new Types());
    }

    /**
     * @template T
     * @param Type<T> $type
     * @return Expression<T>
     */
    public static function parseTyped(string $expression, Type $type, Types|null $types = null): Expression
    {
        $expr = self::parse($expression, $types);
        /** @psalm-suppress ImplicitToStringCast */
        return self::assertExpressionType($expr, $type, sprintf(
            'Expected parsed expression to be of type %s, got %s',
            $type,
            $expr->getType(),
        ));
    }

    /**
     * @param Peekable<ParsedToken> $tokens
     * @return Expression<mixed>
     */
    private static function parseExpression(Peekable $tokens, Types $types): Expression
    {
        $declarations = [];
        /** @var Expression<mixed> | null $expr */
        $expr = null;
        while (true) {
            $newExpr = self::parseLazy($expr, $tokens, $types, $declarations);
            if ($newExpr === null) {
                break;
            }
            if ($newExpr instanceof Declaration) {
                if (array_key_exists($newExpr->name, $declarations)) {
                    throw SyntaxError::create(
                        sprintf('Variable %s is already declared', $newExpr->name),
                        $newExpr->location,
                    );
                }
                $declarations[$newExpr->name] = $newExpr->type;
                continue;
            }
            $expr = $newExpr;
        }
        if ($expr === null) {
            $token = $tokens->peek()?->token;
            throw SyntaxError::create(
                $token === null
                    ? 'Expected expression, got end of input'
                    : sprintf('Expected expression, got %s', Token::print($token)),
                self::nextSpan($tokens),
            );
        }
        return $expr;
    }

    /**
     * @param Expression<mixed> | null $left
     * @param Peekable<ParsedToken> $tokens
     * @param Declarations $declarations
     * @return Expression<mixed> | null
     */
    private static function parseLazy(Expression|null $left, Peekable $tokens, Types $types, array $declarations): Expression|Declaration|null
    {
        $parsedToken = $tokens->peek();
        if ($parsedToken === null) {
            return null;
        }
        $token = $parsedToken->token;
        if ($token === Token::Dot) {
            if ($left === null) {
                self::unexpectedToken($parsedToken);
            }
            return self::call($left, $tokens, $types, $declarations);
        }
        if ($token === 'declare') {
            $tokens->next();
            return self::declaration($parsedToken, $tokens, $types);
        }
        if (is_string($token)) {
            if ($left !== null) {
                throw SyntaxError::create(
                    sprintf('Unexpected identifier %s', $token),
                    Span::char($parsedToken->line, $parsedToken->column),
                );
            }
            return self::variable($token, $tokens, $types, $declarations);
        }
        if ($token instanceof Literal) {
            $tokens->next();
            return Expr::literal($token->value, $parsedToken->location());
        }
        if ($token === Token::TripleEquals) {
            $tokens->next();
            if ($left === null) {
                self::unexpectedToken($parsedToken);
            }
            $right = self::parseExpression($tokens, $types);
            /** @psalm-suppress ImplicitToStringCast */
            $right = self::assertExpressionType($right, $left->getType(), sprintf(
                'The expressions of both sides of === must be of the same type. Left: %s, right: %s',
                $left->getType(),
                $right->getType(),
            ));
            return $left->eq($right);
        }
        if ($token === Token::Or) {
            $tokens->next();
            if ($left === null) {
                self::unexpectedToken($parsedToken);
            }
            /** @psalm-suppress ImplicitToStringCast */
            $left = self::assertExpressionType($left, Type::bool(), sprintf(
                'The expression on the left side of %s must be boolean, got %s',
                Token::print($token),
                $left->getType(),
            ));
            $right = self::parseExpression($tokens, $types);
            /** @psalm-suppress ImplicitToStringCast */
            $right = self::assertExpressionType($right, Type::bool(), sprintf(
                'The expression on the right side of %s must be boolean, got %s',
                Token::print($token),
                $right->getType(),
            ));
            return $left->or_($right);
        }
        if ($token === Token::Pipe) {
            return self::lambda($tokens, $types);
        }
        if ($token === Token::Minus) {
            $tokens->next();
            $right = self::parseLazy(null, $tokens, $types, $declarations);
            if ($right === null) {
                throw SyntaxError::create('Unexpected end of input', Span::char($parsedToken->line, $parsedToken->column + 1));
            }
            /** @phpstan-ignore-next-line False positive */
            if (!$right->matchesType(Type::int()) && !$right->matchesType(Type::float())) {
                /** @psalm-suppress ImplicitToStringCast */
                throw TypeError::create(
                    $left === null
                        ? sprintf('Can\'t negate %s', $right->getType())
                        : sprintf('Can\'t subtract %s from %s', $right->getType(), $left->getType()),
                    $right->location(),
                );
            }
            if ($left === null) {
                /** @phpstan-ignore-next-line False positive */
                return Expr::negative($right, $parsedToken->location()->to($right->location()));
            }
            if (!$left->getType()->equals($right->getType())) {
                /** @psalm-suppress ImplicitToStringCast */
                throw TypeError::create(
                    sprintf('Can\'t subtract %s from %s', $right->getType(), $left->getType()),
                    $left->location(),
                );
            }
            /** @phpstan-ignore-next-line False positive */
            return $left->subtract($right);
        }
        if ($token === Token::CloseAngle) {
            if ($left === null) {
                self::unexpectedToken($parsedToken);
            }
            $tokens->next();
            $right = self::parseExpression($tokens, $types);
            /** @phpstan-ignore-next-line False positive */
            if (!$right->matchesType(Type::int()) && !$right->matchesType(Type::float())) {
                /** @psalm-suppress ImplicitToStringCast */
                throw TypeError::create(sprintf('Can\'t compare %s to %s', $right->getType(), $left->getType()), $right->location());
            }
            if (!$left->matchesType($right->getType())) {
                /** @psalm-suppress ImplicitToStringCast */
                throw TypeError::create(sprintf('Can\'t compare %s to %s', $left->getType(), $right->getType()), $left->location()->to($right->location()));
            }
            /** @phpstan-ignore-next-line False positive */
            return $left->gt($right);
        }
        return null;
    }

    /**
     * foo:MyClass.bar:string
     * ===========
     *
     * @param Peekable<ParsedToken> $tokens
     * @param Declarations $declarations
     * @return Get<mixed>
     */
    private static function variable(string $name, Peekable $tokens, Types $types, array $declarations): Get
    {
        $start = self::expect($tokens, $name);
        $declaration = $declarations[$name] ?? null;
        if ($tokens->peek()?->token !== Token::Colon) {
            if ($declaration !== null) {
                return Expr::get($name, $declaration->type, $start->location());
            }
            throw SyntaxError::create(
                sprintf('Variable %s is not declared and has no type', $name),
                $start->location(),
            );
        }
        self::expect($tokens, Token::Colon);
        $typeNode = self::parseType($tokens);
        if ($typeNode === null) {
            throw SyntaxError::create('Expected type after colon', self::nextSpan($tokens));
        }
        $type = $types->resolve($typeNode);
        if ($type instanceof TypeError) {
            throw $type;
        }
        if ($declaration !== null && !$declaration->type->equals($type)) {
            throw TypeError::create(
                sprintf(
                    'Variable %s is declared as %s at %s, but used as %s',
                    $name,
                    $declaration->type,
                    $declaration->location,
                    $type,
                ),
                $start->location()->to($typeNode->location),
            );
        }
        return Expr::get($name, $type, $start->location()->to($typeNode->location));
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
    private static function parseType(Peekable $tokens): TypeNode|null
    {
        $parsedToken = $tokens->peek();
        if ($parsedToken === null) {
            return null;
        }
        $name = $parsedToken->token;
        if (!is_string($name)) {
            return null;
        }
        $tokens->next();
        if ($tokens->peek()?->token !== Token::OpenAngle) {
            return new TypeNode($name, [], $parsedToken->location());
        }
        $tokens->next();
        $args = self::parseTypeArgs($tokens);
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
    private static function parseTypeArgs(Peekable $tokens): array
    {
        $args = [];
        while (true) {
            $arg = self::parseTypeArg($tokens);
            if ($arg === null) {
                break;
            }
            $args[] = $arg;
        }
        return $args;
    }

    /**
     * map<int, string>
     *     =====
     *
     * @param Peekable<ParsedToken> $tokens
     */
    private static function parseTypeArg(Peekable $tokens): TypeNode|null
    {
        $type = self::parseType($tokens);
        if ($type === null) {
            return null;
        }
        $nextToken = $tokens->peek();
        assert($nextToken !== null);
        if ($nextToken->token === Token::CloseAngle) {
            return $type;
        }
        self::expect($tokens, Token::Comma);
        return $type;
    }

    /**
     * some(foo:list<string>, |item| item:string === bar:string)
     *      ===================================================
     *
     * @param Peekable<ParsedToken> $tokens
     * @param Declarations $declarations
     * @return list<Expression<mixed>>
     */
    private static function parseArgs(Peekable $tokens, Types $types, array $declarations): array
    {
        $args = [];
        while (true) {
            $arg = self::parseArg($tokens, $types, $declarations);
            if ($arg === null) {
                break;
            }
            $args[] = $arg;
        }
        return $args;
    }

    /**
     * some(foo:list<string>, |item| item:string === bar:string)
     *      ==================
     *
     * @param Peekable<ParsedToken> $tokens
     * @param Declarations $declarations
     * @return Expression<mixed> | null
     */
    private static function parseArg(Peekable $tokens, Types $types, array $declarations): Expression|null
    {
        $token = $tokens->peek()?->token;
        if ($token === Token::CloseParen) {
            return null;
        }
        $arg = self::parseLazy(null, $tokens, $types, $declarations);
        if ($arg === null) {
            return null;
        }
        $token = $tokens->peek()?->token;
        if ($token === Token::Comma) {
            $tokens->next();
        }
        return $arg;
    }

    /**
     * |item| => item:string === needle:string
     * =======================================
     *
     * @param Peekable<ParsedToken> $tokens
     * @return Expression<callable(Scope): mixed>
     */
    private static function lambda(Peekable $tokens, Types $types): Expression
    {
        $start = self::expect($tokens, Token::Pipe);
        $args = self::parseParams($tokens);
        self::expect($tokens, Token::Pipe);
        $body = self::parseExpression($tokens, $types);
        return Expr::lambda($body, $args, $start->location()->to($body->location()));
    }

    /**
     * |one, two, three, | => foo:string
     *  =================
     * @param Peekable<ParsedToken> $tokens
     * @return list<string>
     */
    private static function parseParams(Peekable $tokens): array
    {
        $params = [];
        while (true) {
            $param = self::parseParam($tokens);
            if ($param === null) {
                break;
            }
            $params[] = $param;
        }
        return $params;
    }

    /**
     * |foo, bar| foo:bool === bar:bool
     *  =====
     *
     * @param Peekable<ParsedToken> $tokens
     */
    private static function parseParam(Peekable $tokens): string|null
    {
        $token = $tokens->peek()?->token;
        if (!is_string($token)) {
            return null;
        }
        $tokens->next();
        if ($tokens->peek()?->token !== Token::Pipe) {
            self::expect($tokens, Token::Comma);
        }
        return $token;
    }

    /**
     * @template T
     * @param Expression<mixed> $expr
     * @param Type<T> $type
     * @return Expression<T>
     */
    private static function assertExpressionType(Expression $expr, Type $type, string $errorMessage): Expression
    {
        /** @psalm-suppress RedundantCondition False positive. This check is _not_ redundant. */
        if ($expr->matchesType($type)) {
            return $expr;
        }
        /**
         * @psalm-suppress MixedArgument False positive
         * @psalm-suppress MixedMethodCall False positive
         */
        throw TypeError::create($errorMessage, $expr->location());
    }

    private static function unexpectedToken(ParsedToken $token): never
    {
        throw SyntaxError::create(sprintf('Unexpected %s', Token::print($token->token)), $token->location());
    }

    /**
     * list<string>.some:bool(|item| item:string === needle:string)
     *             ================================================
     *
     * @template T
     * @param Expression<T> $target
     * @param Peekable<ParsedToken> $tokens
     * @param Declarations $declarations
     * @return Call<mixed>
     */
    private static function call(Expression $target, Peekable $tokens, Types $types, array $declarations): Call
    {
        $dot = self::expect($tokens, Token::Dot);
        $name = self::expectIdentifier($tokens, $dot, 'function name');
        self::expect($tokens, Token::Colon);
        $type = self::parseType($tokens);
        if ($type === null) {
            throw SyntaxError::create('Expected type after colon', self::nextSpan($tokens));
        }
        $type = $types->resolve($type);
        if ($type instanceof TypeError) {
            throw $type;
        }
        self::expect($tokens, Token::OpenParen);
        $args = self::parseArgs($tokens, $types, $declarations);
        $closeParen = self::expect($tokens, Token::CloseParen);
        return $target->call($name, $type, $args, $target->location()->to($closeParen->location()));
    }

    /**
     * @param Peekable<ParsedToken> $tokens
     */
    private static function expectIdentifier(
        Peekable $tokens,
        ParsedToken $lastToken,
        string $expected = 'identifier',
    ): string
    {
        $name = $tokens->peek();
        if ($name === null) {
            throw SyntaxError::create(
                sprintf('Expected %s, got end of input', $expected),
                Span::char($lastToken->line, $lastToken->column + 1),
            );
        }
        if (!is_string($name->token)) {
            throw SyntaxError::create(
                sprintf('Expected %s, got %s', $expected, Token::print($name->token)),
                Span::char($name->line, $name->column),
            );
        }
        $tokens->next();
        return $name->token;
    }

    /**
     * @param Peekable<ParsedToken> $tokens
     */
    private static function nextSpan(Peekable $tokens): Span
    {
        $token = $tokens->peek();
        if ($token !== null) {
            return Span::char($token->line, $token->column);
        }
        $previous = $tokens->previous();
        return $previous === null ? Span::char(1, 1) : Span::char($previous->line, $previous->column + 1);
    }

    private static function declaration(ParsedToken $declareKeyword, Peekable $tokens, Types $types): Declaration
    {
        $name = self::expectIdentifier($tokens, $declareKeyword, 'variable name');
        self::expect($tokens, Token::Colon);
        $typeNode = self::parseType($tokens);
        if ($typeNode === null) {
            throw SyntaxError::create('Expected type after colon', self::nextSpan($tokens));
        }
        $type = $types->resolve($typeNode);
        if ($type instanceof TypeError) {
            throw $type;
        }
        return new Declaration($name, $type, $declareKeyword->location()->to($typeNode->location));
    }
}
