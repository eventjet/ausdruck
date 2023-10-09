<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Parser;

use Eventjet\Ausdruck\Call;
use Eventjet\Ausdruck\Expr;
use Eventjet\Ausdruck\Expression;
use Eventjet\Ausdruck\Get;
use Eventjet\Ausdruck\Scope;
use Eventjet\Ausdruck\Type;

use function array_map;
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
     * @return Span<Expression<mixed>>
     */
    public static function parse(string $expression, Types|null $types = null): Span
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
     * @return Span<Expression<T>>
     */
    public static function parseTyped(string $expression, Type $type, Types|null $types = null): Span
    {
        $expr = self::parse($expression, $types);
        /** @psalm-suppress ImplicitToStringCast */
        return self::assertExpressionType($expr, $type, sprintf(
            'Expected parsed expression to be of type %s, got %s',
            $type,
            $expr->value->getType(),
        ));
    }

    /**
     * @param Peekable<Span<AnyToken>> $tokens
     * @return Span<Expression<mixed>>
     */
    private static function parseExpression(Peekable $tokens, Types $types): Span
    {
        /** @var Span<Expression<mixed>> | null $expr */
        $expr = null;
        while (true) {
            $newExpr = self::parseLazy($expr, $tokens, $types);
            if ($newExpr === null) {
                break;
            }
            $expr = $newExpr;
        }
        if ($expr === null) {
            $token = $tokens->peek()?->value;
            throw new SyntaxError(
                $token === null
                    ? 'Expected expression, got end of input'
                    : sprintf('Expected expression, got %s', Token::print($token)),
            );
        }
        return $expr;
    }

    /**
     * @param Span<Expression<mixed>> | null $left
     * @param Peekable<Span<AnyToken>> $tokens
     * @return Span<Expression<mixed>> | null
     */
    private static function parseLazy(Span|null $left, Peekable $tokens, Types $types): Span|null
    {
        $span = $tokens->peek();
        $token = $span?->value;
        if ($token === null) {
            return null;
        }
        assert($span !== null);
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
            return new Span(
                Expr::literal($token->value),
                $span->startLine,
                $span->startColumn,
                $span->endLine,
                $span->endColumn,
            );
        }
        if ($token === Token::TripleEquals) {
            $tokens->next();
            if ($left === null) {
                self::unexpectedToken($token);
            }
            $right = self::parseExpression($tokens, $types);
            /** @psalm-suppress ImplicitToStringCast */
            $right = self::assertExpressionType($right, $left->value->getType(), sprintf(
                'The expressions of both sides of === must be of the same type. Left: %s, right: %s',
                $left->value->getType(),
                $right->value->getType(),
            ));
            return new Span(
                $left->value->eq($right->value),
                $left->startLine,
                $left->startColumn,
                $right->endLine,
                $right->endColumn,
            );
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
                $left->value->getType(),
            ));
            $right = self::parseExpression($tokens, $types);
            /** @psalm-suppress ImplicitToStringCast */
            $right = self::assertExpressionType($right, Type::bool(), sprintf(
                'The expression on the right side of %s must be boolean, got %s',
                Token::print($token),
                $right->value->getType(),
            ));
            return new Span(
                $left->value->or_($right->value),
                $left->startLine,
                $left->startColumn,
                $right->endLine,
                $right->endColumn,
            );
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
            if (!$subtrahend->value->matchesType(Type::int()) && !$subtrahend->value->matchesType(Type::float())) {
                /** @psalm-suppress ImplicitToStringCast */
                throw new TypeError(
                    sprintf('Can\'t subtract %s from %s', $subtrahend->value->getType(), $left->value->getType()),
                );
            }
            if (!$left->value->getType()->equals($subtrahend->value->getType())) {
                /** @psalm-suppress ImplicitToStringCast */
                throw new TypeError(
                    sprintf('Can\'t subtract %s from %s', $subtrahend->value->getType(), $left->value->getType()),
                );
            }
            return new Span(
                /** @phpstan-ignore-next-line False positive */
                $left->value->subtract($subtrahend->value),
                $left->startLine,
                $left->startColumn,
                $subtrahend->endLine,
                $subtrahend->endColumn,
            );
        }
        if ($token === Token::CloseAngle) {
            if ($left === null) {
                self::unexpectedToken($token);
            }
            $tokens->next();
            $right = self::parseExpression($tokens, $types);
            /** @phpstan-ignore-next-line False positive */
            if (!$right->value->matchesType(Type::int()) && !$right->value->matchesType(Type::float())) {
                /** @psalm-suppress ImplicitToStringCast */
                throw new TypeError(
                    sprintf('Can\'t compare %s to %s', $right->value->getType(), $left->value->getType()),
                );
            }
            if (!$left->value->matchesType($right->value->getType())) {
                /** @psalm-suppress ImplicitToStringCast */
                throw new TypeError(
                    sprintf('Can\'t compare %s to %s', $left->value->getType(), $right->value->getType()),
                );
            }
            return new Span(
                /** @phpstan-ignore-next-line False positive */
                $left->value->gt($right->value),
                $left->startLine,
                $left->startColumn,
                $right->endLine,
                $right->endColumn,
            );
        }
        return null;
    }

    /**
     * foo:MyClass.bar:string
     * ===========
     *
     * @param Peekable<Span<AnyToken>> $tokens
     * @return Span<Get<mixed>>
     */
    private static function variable(string $name, Peekable $tokens, Types $types): Span
    {
        $startLine = $tokens->peek()?->startLine;
        $startColumn = $tokens->peek()?->startColumn;
        self::expect($tokens, $name);
        assert($startLine !== null);
        assert($startColumn !== null);
        self::expect($tokens, Token::Colon);
        $typeNode = self::parseType($tokens);
        if ($typeNode === null) {
            throw new SyntaxError('Expected type after colon');
        }
        $type = $types->resolve($typeNode->value);
        if ($type instanceof TypeError) {
            throw $type;
        }
        return new Span(Expr::get($name, $type), $startLine, $startColumn, $typeNode->endLine, $typeNode->endColumn);
    }

    /**
     * @param Peekable<Span<AnyToken>> $tokens
     * @param AnyToken $expected
     */
    private static function expect(Peekable $tokens, Token|string|Literal $expected): void
    {
        $actual = $tokens->peek()?->value;
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
     * @param Peekable<Span<AnyToken>> $tokens
     * @param AnyToken $expected
     */
    private static function skip(Peekable $tokens, Token|string|Literal $expected): void
    {
        $actual = $tokens->peek()?->value;
        if ($actual !== $expected) {
            return;
        }
        $tokens->next();
    }

    /**
     * @param Peekable<Span<AnyToken>> $tokens
     * @return Span<TypeNode> | null
     */
    private static function parseType(Peekable $tokens): Span|null
    {
        $idSpan = $tokens->peek();
        $name = $idSpan?->value;
        if (!is_string($name)) {
            return null;
        }
        assert($idSpan !== null);
        $tokens->next();
        if ($tokens->peek()?->value !== Token::OpenAngle) {
            return new Span(
                new TypeNode($name),
                $idSpan->startLine,
                $idSpan->startColumn,
                $idSpan->endLine,
                $idSpan->endColumn,
            );
        }
        $tokens->next();
        $args = self::parseTypeArgs($tokens);
        $endToken = $tokens->peek();
        self::expect($tokens, Token::CloseAngle);
        return new Span(
            new TypeNode($name, array_map(static fn(Span $s): TypeNode => $s->value, $args)),
            $idSpan->startLine,
            $idSpan->startColumn,
            $endToken->endLine,
            $endToken->endColumn,
        );
    }

    /**
     * map<int, string>
     *     ===========
     *
     * @param Peekable<Span<AnyToken>> $tokens
     * @return list<Span<TypeNode>>
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
     * @param Peekable<Span<AnyToken>> $tokens
     * @return Span<TypeNode> | null
     */
    private static function parseTypeArg(Peekable $tokens): Span|null
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
     * @param Peekable<Span<AnyToken>> $tokens
     * @return list<Span<Expression<mixed>>>
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
     * @param Peekable<Span<AnyToken>> $tokens
     * @return Span<Expression<mixed>> | null
     */
    private static function parseArg(Peekable $tokens, Types $types): Span|null
    {
        $token = $tokens->peek()?->value;
        if ($token === Token::CloseParen) {
            return null;
        }
        $arg = self::parseExpression($tokens, $types);
        $token = $tokens->peek()?->value;
        if ($token === Token::Comma) {
            $tokens->next();
        }
        return $arg;
    }

    /**
     * |item| => item:string === needle:string
     * =======================================
     *
     * @param Peekable<Span<AnyToken>> $tokens
     * @return Span<Expression<callable(Scope): mixed>>
     */
    private static function lambda(Peekable $tokens, Types $types): Span
    {
        $startToken = $tokens->peek();
        $startLine = $startToken?->startLine;
        $startColumn = $startToken?->startColumn;
        self::expect($tokens, Token::Pipe);
        assert($startLine !== null);
        assert($startColumn !== null);
        $args = self::parseParams($tokens);
        self::expect($tokens, Token::Pipe);
        $body = self::parseExpression($tokens, $types);
        return new Span(Expr::lambda($body->value, $args), $startLine, $startColumn, $body->endLine, $body->endColumn);
    }

    /**
     * |one, two, three| => foo:string
     *  ===============
     * @param Peekable<Span<AnyToken>> $tokens
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
     * @param Peekable<Span<AnyToken>> $tokens
     */
    private static function parseParam(Peekable $tokens): string|null
    {
        $token = $tokens->peek()?->value;
        if (!is_string($token)) {
            return null;
        }
        $tokens->next();
        self::skip($tokens, Token::Comma);
        return $token;
    }

    /**
     * @template T
     * @param Span<Expression<mixed>> $expr
     * @param Type<T> $type
     * @return Span<Expression<T>>
     */
    private static function assertExpressionType(Span $expr, Type $type, string $errorMessage): Span
    {
        /** @psalm-suppress RedundantCondition False positive. This check is _not_ redundant. */
        if ($expr->value->matchesType($type)) {
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
     * @param Span<Expression<T>> $target
     * @param Peekable<Span<AnyToken>> $tokens
     * @return Span<Call<mixed>>
     */
    private static function call(Span $target, Peekable $tokens, Types $types): Span
    {
        self::expect($tokens, Token::Dot);
        $name = self::expectIdentifier($tokens, 'function name');
        self::expect($tokens, Token::Colon);
        $type = self::parseType($tokens);
        if ($type === null) {
            throw new SyntaxError('Expected type after colon');
        }
        $type = $types->resolve($type->value);
        if ($type instanceof TypeError) {
            throw $type;
        }
        self::expect($tokens, Token::OpenParen);
        $args = self::parseArgs($tokens, $types);
        $endToken = $tokens->peek();
        $endLine = $endToken?->endLine;
        $endColumn = $endToken?->endColumn;
        self::expect($tokens, Token::CloseParen);
        assert($endLine !== null);
        assert($endColumn !== null);
        return new Span(
            $target->value->call($name, $type, array_map(static fn(Span $s): Expression => $s->value, $args)),
            $target->startLine,
            $target->startColumn,
            $endLine,
            $endColumn,
        );
    }

    /**
     * @param Peekable<Span<AnyToken>> $tokens
     */
    private static function expectIdentifier(Peekable $tokens, string $expected = 'identifier'): string
    {
        $name = $tokens->peek();
        if ($name === null) {
            throw new SyntaxError(sprintf('Expected %s, got end of input', $expected));
        }
        if (!is_string($name->value)) {
            throw new SyntaxError(sprintf('Expected %s, got %s', $expected, Token::print($name->value)));
        }
        $tokens->next();
        return $name->value;
    }
}
