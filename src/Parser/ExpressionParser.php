<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Parser;

use Eventjet\Ausdruck\Call;
use Eventjet\Ausdruck\Expr;
use Eventjet\Ausdruck\Expression;
use Eventjet\Ausdruck\Get;
use Eventjet\Ausdruck\ListLiteral;
use Eventjet\Ausdruck\Scope;
use Eventjet\Ausdruck\Type;

use function array_shift;
use function assert;
use function count;
use function in_array;
use function is_string;
use function sprintf;
use function str_split;

/**
 * @phpstan-type AnyToken Token | string | Literal<string | int | float>
 * @api
 */
final class ExpressionParser
{
    /**
     * @return Expression<mixed>
     */
    public static function parse(string $expression, Declarations|Types|null $types = null): Expression
    {
        if ($types === null) {
            $types = new Types();
        }
        if ($types instanceof Types) {
            $types = new Declarations($types);
        }
        $declarations = $types;
        $chars = $expression === '' ? [] : str_split($expression);
        /**
         * @infection-ignore-all Currently, there's no difference between str_split and its multibyte version. Multibyte
         *     string literals and identifiers are just put back together. If you encounter a case where it does matter,
         *     just change it to mb_str_split and add an appropriate test case.
         */
        return self::parseExpression(new Peekable(Tokenizer::tokenize($chars)), $declarations);
    }

    /**
     * @template T
     * @param Type<T> $type
     * @return Expression<T>
     */
    public static function parseTyped(string $expression, Type $type, Declarations|Types|null $types = null): Expression
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
    private static function parseExpression(Peekable $tokens, Declarations $declarations): Expression
    {
        /** @var Expression<mixed> | null $expr */
        $expr = null;
        while (true) {
            $newExpr = self::parseLazy($expr, $tokens, $declarations);
            if ($newExpr === null) {
                break;
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
     * @return Expression<mixed> | null
     */
    private static function parseLazy(Expression|null $left, Peekable $tokens, Declarations $declarations): Expression|null
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
            return self::call($left, $tokens, $declarations);
        }
        if ($token === Token::Arrow) {
            if ($left === null) {
                self::unexpectedToken($parsedToken);
            }
            self::assertExpressionType(
                $left,
                Type::struct([]),
                sprintf('The left side of -> must be a struct, got %s', $left->getType()),
            );
            $tokens->next();
            $fieldName = self::expectIdentifier($tokens, $parsedToken);
            /**
             * @psalm-suppress MixedArgumentTypeCoercion False positive?
             * @phpstan-ignore-next-line False positive?
             */
            return Expr::structField($left, $fieldName[0], $fieldName[1]);
        }
        if (is_string($token)) {
            if ($left !== null) {
                throw SyntaxError::create(
                    sprintf('Unexpected identifier %s', $token),
                    Span::char($parsedToken->line, $parsedToken->column),
                );
            }
            return self::variable($token, $tokens, $declarations);
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
            $right = self::parseExpression($tokens, $declarations);
            /** @psalm-suppress ImplicitToStringCast */
            $right = self::assertExpressionType($right, $left->getType(), sprintf(
                'The expressions of both sides of === must be of the same type. Left: %s, right: %s',
                $left->getType(),
                $right->getType(),
            ));
            return $left->eq($right);
        }
        if (in_array($token, [Token::Or, Token::And], true)) {
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
            $right = self::parseExpression($tokens, $declarations);
            /** @psalm-suppress ImplicitToStringCast */
            $right = self::assertExpressionType($right, Type::bool(), sprintf(
                'The expression on the right side of %s must be boolean, got %s',
                Token::print($token),
                $right->getType(),
            ));
            return $token === Token::Or ? $left->or_($right) : $left->and_($right);
        }
        if ($token === Token::Pipe) {
            return self::lambda($tokens, $declarations);
        }
        if ($token === Token::Minus) {
            $tokens->next();
            $right = self::parseLazy(null, $tokens, $declarations);
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
            $right = self::parseExpression($tokens, $declarations);
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
        if ($token === Token::OpenBracket) {
            return self::parseListLiteral($tokens, $declarations);
        }
        return null;
    }

    /**
     * foo:MyClass.bar:string
     * ===========
     *
     * @param Peekable<ParsedToken> $tokens
     * @return Get<mixed>
     */
    private static function variable(string $name, Peekable $tokens, Declarations $declarations): Get
    {
        $start = self::expect($tokens, $name);
        $declaredType = $declarations->variables[$name] ?? null;
        if ($tokens->peek()?->token !== Token::Colon) {
            if ($declaredType !== null) {
                return Expr::get($name, new TypeHint($declaredType, false), $start->location());
            }
            throw SyntaxError::create(
                sprintf('Variable %s must either be declared or have an inline type', $name),
                $start->location(),
            );
        }
        self::expect($tokens, Token::Colon);
        $typeNode = TypeParser::parse($tokens);
        if ($typeNode === null) {
            throw SyntaxError::create('Expected type, got end of string', self::nextSpan($tokens));
        }
        if ($typeNode instanceof ParsedToken) {
            throw SyntaxError::create(
                sprintf('Expected type, got %s', Token::print($typeNode->token)),
                $typeNode->location(),
            );
        }
        $type = $declarations->types->resolve($typeNode);
        if ($type instanceof TypeError) {
            throw $type;
        }
        if ($declaredType !== null && !$declaredType->equals($type)) {
            throw TypeError::create(
                sprintf(
                    'Variable %s is declared as %s, but used as %s',
                    $name,
                    $declaredType,
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
     * some(foo:list<string>, |item| item:string === bar:string)
     *      ===================================================
     *
     * @param Peekable<ParsedToken> $tokens
     * @return list<Expression<mixed>>
     */
    private static function parseArgs(Peekable $tokens, Declarations $declarations): array
    {
        $args = [];
        while (true) {
            $arg = self::parseArg($tokens, $declarations);
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
     * @return Expression<mixed> | null
     */
    private static function parseArg(Peekable $tokens, Declarations $declarations): Expression|null
    {
        $token = $tokens->peek()?->token;
        if ($token === Token::CloseParen) {
            return null;
        }
        $arg = self::parseLazy(null, $tokens, $declarations);
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
    private static function lambda(Peekable $tokens, Declarations $declarations): Expression
    {
        $start = self::expect($tokens, Token::Pipe);
        $args = self::parseParams($tokens);
        self::expect($tokens, Token::Pipe);
        $body = self::parseExpression($tokens, $declarations);
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
        if ($expr->isSubtypeOf($type)) {
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
     * @return Call<mixed>
     */
    private static function call(Expression $target, Peekable $tokens, Declarations $declarations): Call
    {
        $dot = self::expect($tokens, Token::Dot);
        [$name, $nameLocation] = self::expectIdentifier($tokens, $dot, 'function name');
        $fnType = $declarations->functions[$name] ?? null;
        if ($tokens->peek()?->token === Token::Colon) {
            $tokens->next();
            $typeNode = TypeParser::parse($tokens);
            if ($typeNode === null) {
                throw SyntaxError::create('Expected type after colon', self::nextSpan($tokens));
            }
            if ($typeNode instanceof ParsedToken) {
                throw SyntaxError::create(
                    sprintf('Expected type after colon, got %s', Token::print($typeNode->token)),
                    $typeNode->location(),
                );
            }
            $returnType = $declarations->types->resolve($typeNode);
            if ($returnType instanceof TypeError) {
                throw $returnType;
            }
            if ($fnType !== null && !$returnType->isSubtypeOf($fnType->args[0])) {
                throw TypeError::create(
                    sprintf(
                        'Inline return type %s of function %s does not match declared return type %s',
                        $returnType,
                        $name,
                        $fnType->returnType(),
                    ),
                    $typeNode->location,
                );
            }
        } else {
            if ($fnType === null) {
                throw TypeError::create(
                    sprintf('Function %s is not declared and has no inline type', $name),
                    $nameLocation,
                );
            }
            $returnType = $fnType->args[0];
        }
        if ($fnType !== null) {
            $targetType = $fnType->args[1] ?? null;
            if ($targetType === null) {
                throw new TypeError(
                    sprintf('%s can\'t be used as a receiver function because it doesn\'t accept any arguments', $name),
                );
            }
            if (!$target->isSubtypeOf($targetType)) {
                throw new TypeError(
                    sprintf(
                        '%s must be called on an expression of type %s, but %s is of type %s',
                        $name,
                        $targetType,
                        $target,
                        $target->getType(),
                    ),
                );
            }
        }
        self::expect($tokens, Token::OpenParen);
        $args = self::parseArgs($tokens, $declarations);
        $closeParen = self::expect($tokens, Token::CloseParen);
        if ($fnType !== null) {
            $parameterTypes = $fnType->args;
            array_shift($parameterTypes); // Remove return type
            array_shift($parameterTypes); // Remove receiver type
            foreach ($parameterTypes as $index => $parameterType) {
                $argument = $args[$index] ?? null;
                if ($argument === null) {
                    throw new TypeError(
                        sprintf('%s expects %d arguments, got %d', $name, count($parameterTypes), count($args)),
                    );
                }
                if (!$argument->isSubtypeOf($parameterType)) {
                    throw new TypeError(
                        sprintf(
                            'Argument %d of %s must be of type %s, got %s',
                            $index + 1,
                            $name,
                            $parameterType,
                            $argument->getType(),
                        ),
                    );
                }
            }
        }
        return $target->call($name, $returnType, $args, $target->location()->to($closeParen->location()));
    }

    /**
     * @param Peekable<ParsedToken> $tokens
     * @return array{string, Span}
     */
    private static function expectIdentifier(
        Peekable $tokens,
        ParsedToken $lastToken,
        string $expected = 'identifier',
    ): array {
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
        return [$name->token, $name->location()];
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

    /**
     * @param Peekable<ParsedToken> $tokens
     * @return ListLiteral<mixed>
     */
    private static function parseListLiteral(Peekable $tokens, Declarations $declarations): ListLiteral
    {
        $start = self::expect($tokens, Token::OpenBracket);
        $items = [];
        while (true) {
            $item = self::parseLazy(null, $tokens, $declarations);
            if ($item === null) {
                break;
            }
            $items[] = $item;
            if ($tokens->peek()?->token === Token::Comma) {
                $tokens->next();
            }
        }
        $close = self::expect($tokens, Token::CloseBracket);
        return Expr::listLiteral($items, $start->location()->to($close->location()));
    }
}
