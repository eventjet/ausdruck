<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Parser;

use Eventjet\Ausdruck\Call;
use Eventjet\Ausdruck\Expr;
use Eventjet\Ausdruck\Expression;
use Eventjet\Ausdruck\Get;
use Eventjet\Ausdruck\Scope;
use Eventjet\Ausdruck\Type;

use function assert;
use function is_string;
use function sprintf;
use function str_split;

/**
 * @phpstan-type AnyToken Token | string | Literal<string | int | float>
 * @api
 */
final readonly class ExpressionParser
{
    /**
     * @return Expression<mixed>
     */
    public static function parse(string $expression, Types|null $types = null): Expression
    {
        /**
         * @infection-ignore-all Currently, there's no difference between str_split and its multibyte version. Multibyte
         *     string literals and identifiers are just put back together. If you encounter a case where it does matter,
         *     just change it to mb_str_split and add an appropriate test case.
         */
        return self::parseExpression(new Peekable(Tokenizer::tokenize(str_split($expression))), $types ?? new Types());
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
     * @param Peekable<AnyToken> $tokens
     * @return Expression<mixed>
     */
    private static function parseExpression(Peekable $tokens, Types $types): Expression
    {
        /** @var Expression<mixed> | null $expr */
        $expr = null;
        while (true) {
            $newExpr = self::parseLazy($expr, $tokens, $types);
            if ($newExpr === null) {
                break;
            }
            $expr = $newExpr;
        }
        if ($expr === null) {
            $token = $tokens->peek();
            throw new SyntaxError(
                $token === null
                    ? 'Expected expression, got end of input'
                    : sprintf('Expected expression, got %s', Token::print($token)),
            );
        }
        return $expr;
    }

    /**
     * @param Expression<mixed> | null $left
     * @param Peekable<AnyToken> $tokens
     * @return Expression<mixed> | null
     */
    private static function parseLazy(Expression|null $left, Peekable $tokens, Types $types): Expression|null
    {
        $token = $tokens->peek();
        if ($token === null) {
            return null;
        }
        if ($token === Token::Dot) {
            if ($left === null) {
                self::unexpectedToken($token);
            }
            return self::call($left, $tokens, $types);
        }
        if (is_string($token)) {
            if ($left !== null) {
                throw new SyntaxError(sprintf('Unexpected identifier %s', $token));
            }
            return self::variable($token, $tokens, $types);
        }
        if ($token instanceof Literal) {
            $tokens->next();
            return Expr::literal($token->value);
        }
        if ($token === Token::TripleEquals) {
            $tokens->next();
            if ($left === null) {
                self::unexpectedToken($token);
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
                self::unexpectedToken($token);
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
            assert($left !== null, 'If an expression starts with a minus, it was handled by the int literal case');
            $subtrahend = self::parseLazy(null, $tokens, $types);
            if ($subtrahend === null) {
                throw new SyntaxError('Expected expression after minus');
            }
            /** @phpstan-ignore-next-line False positive */
            if (!$subtrahend->matchesType(Type::int()) && !$subtrahend->matchesType(Type::float())) {
                /** @psalm-suppress ImplicitToStringCast */
                throw new TypeError(sprintf('Can\'t subtract %s from %s', $subtrahend->getType(), $left->getType()));
            }
            if (!$left->getType()->equals($subtrahend->getType())) {
                /** @psalm-suppress ImplicitToStringCast */
                throw new TypeError(sprintf('Can\'t subtract %s from %s', $subtrahend->getType(), $left->getType()));
            }
            /** @phpstan-ignore-next-line False positive */
            return $left->subtract($subtrahend);
        }
        if ($token === Token::CloseAngle) {
            if ($left === null) {
                self::unexpectedToken($token);
            }
            $tokens->next();
            $right = self::parseExpression($tokens, $types);
            /** @phpstan-ignore-next-line False positive */
            if (!$right->matchesType(Type::int()) && !$right->matchesType(Type::float())) {
                /** @psalm-suppress ImplicitToStringCast */
                throw new TypeError(sprintf('Can\'t compare %s to %s', $right->getType(), $left->getType()));
            }
            if (!$left->matchesType($right->getType())) {
                /** @psalm-suppress ImplicitToStringCast */
                throw new TypeError(sprintf('Can\'t compare %s to %s', $left->getType(), $right->getType()));
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
     * @param Peekable<AnyToken> $tokens
     * @return Get<mixed>
     */
    private static function variable(string $name, Peekable $tokens, Types $types): Get
    {
        self::expect($tokens, $name);
        self::expect($tokens, Token::Colon);
        $typeNode = self::parseType($tokens);
        if ($typeNode === null) {
            throw new SyntaxError('Expected type after colon');
        }
        $type = $types->resolve($typeNode);
        if ($type instanceof TypeError) {
            throw $type;
        }
        return Expr::get($name, $type);
    }

    /**
     * @param Peekable<AnyToken> $tokens
     * @param AnyToken $expected
     */
    private static function expect(Peekable $tokens, Token|string|Literal $expected): void
    {
        $actual = $tokens->peek();
        if ($actual === null) {
            throw new SyntaxError(sprintf('Expected %s, got end of input', Token::print($expected)));
        }
        if ($actual === $expected) {
            $tokens->next();
            return;
        }
        throw new SyntaxError(sprintf('Expected %s, got %s', Token::print($expected), Token::print($actual)));
    }

    /**
     * @param Peekable<AnyToken> $tokens
     * @param AnyToken $expected
     */
    private static function skip(Peekable $tokens, Token|string|Literal $expected): void
    {
        $actual = $tokens->peek();
        if ($actual !== $expected) {
            return;
        }
        $tokens->next();
    }

    /**
     * @param Peekable<AnyToken> $tokens
     */
    private static function parseType(Peekable $tokens): TypeNode|null
    {
        $name = $tokens->peek();
        if (!is_string($name)) {
            return null;
        }
        $tokens->next();
        if ($tokens->peek() !== Token::OpenAngle) {
            return new TypeNode($name);
        }
        $tokens->next();
        $args = self::parseTypeArgs($tokens);
        self::expect($tokens, Token::CloseAngle);
        return new TypeNode($name, $args);
    }

    /**
     * map<int, string>
     *     ===========
     *
     * @param Peekable<AnyToken> $tokens
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
     * @param Peekable<AnyToken> $tokens
     */
    private static function parseTypeArg(Peekable $tokens): TypeNode|null
    {
        $type = self::parseType($tokens);
        if ($type === null) {
            return null;
        }
        self::skip($tokens, Token::Comma);
        return $type;
    }

    /**
     * some(foo:list<string>, |item| item:string === bar:string)
     *      ===================================================
     *
     * @param Peekable<AnyToken> $tokens
     * @return list<Expression<mixed>>
     */
    private static function parseArgs(Peekable $tokens, Types $types): array
    {
        $args = [];
        while (true) {
            $arg = self::parseArg($tokens, $types);
            if ($arg === null) {
                break;
            }
            $args[] = $arg;
            if ($tokens->peek() === null) {
                break;
            }
        }
        return $args;
    }

    /**
     * some(foo:list<string>, |item| item:string === bar:string)
     *      ==================
     *
     * @param Peekable<AnyToken> $tokens
     * @return Expression<mixed> | null
     */
    private static function parseArg(Peekable $tokens, Types $types): Expression|null
    {
        $token = $tokens->peek();
        if ($token === Token::CloseParen) {
            return null;
        }
        $arg = self::parseExpression($tokens, $types);
        $token = $tokens->peek();
        if ($token === Token::Comma) {
            $tokens->next();
        }
        return $arg;
    }

    /**
     * |item| => item:string === needle:string
     * =======================================
     *
     * @param Peekable<AnyToken> $tokens
     * @return Expression<callable(Scope): mixed>
     */
    private static function lambda(Peekable $tokens, Types $types): Expression
    {
        self::expect($tokens, Token::Pipe);
        $args = self::parseParams($tokens);
        self::expect($tokens, Token::Pipe);
        $body = self::parseExpression($tokens, $types);
        return Expr::lambda($body, $args);
    }

    /**
     * |one, two, three| => foo:string
     *  ===============
     * @param Peekable<AnyToken> $tokens
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
     * @param Peekable<AnyToken> $tokens
     */
    private static function parseParam(Peekable $tokens): string|null
    {
        $token = $tokens->peek();
        if (!is_string($token)) {
            return null;
        }
        $tokens->next();
        self::skip($tokens, Token::Comma);
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
        throw new TypeError($errorMessage);
    }

    /**
     * @param AnyToken $token
     */
    private static function unexpectedToken(Token|string|Literal $token): never
    {
        throw new SyntaxError(sprintf('Unexpected %s', Token::print($token)));
    }

    /**
     * list<string>.some:bool(|item| item:string === needle:string)
     *             ================================================
     *
     * @template T
     * @param Expression<T> $target
     * @param Peekable<AnyToken> $tokens
     * @return Call<mixed>
     */
    private static function call(Expression $target, Peekable $tokens, Types $types): Call
    {
        self::expect($tokens, Token::Dot);
        $name = self::expectIdentifier($tokens, 'function name');
        self::expect($tokens, Token::Colon);
        $type = self::parseType($tokens);
        if ($type === null) {
            throw new SyntaxError('Expected type after colon');
        }
        $type = $types->resolve($type);
        if ($type instanceof TypeError) {
            throw $type;
        }
        self::expect($tokens, Token::OpenParen);
        $args = self::parseArgs($tokens, $types);
        self::expect($tokens, Token::CloseParen);
        return $target->call($name, $type, $args);
    }

    /**
     * @param Peekable<AnyToken> $tokens
     */
    private static function expectIdentifier(Peekable $tokens, string $expected = 'identifier'): string
    {
        $name = $tokens->peek();
        if ($name === null) {
            throw new SyntaxError(sprintf('Expected %s, got end of input', $expected));
        }
        if (!is_string($name)) {
            throw new SyntaxError(sprintf('Expected %s, got %s', $expected, Token::print($name)));
        }
        $tokens->next();
        return $name;
    }
}
