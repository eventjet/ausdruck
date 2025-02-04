<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Parser;

use Eventjet\Ausdruck\Call;
use Eventjet\Ausdruck\Expr;
use Eventjet\Ausdruck\Expression;
use Eventjet\Ausdruck\FieldAccess;
use Eventjet\Ausdruck\Get;
use Eventjet\Ausdruck\ListLiteral;
use Eventjet\Ausdruck\StructLiteral;
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
     * @param Peekable<ParsedToken> $tokens
     */
    private function __construct(private Peekable $tokens, private Declarations $declarations)
    {
    }

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
        return (new self(new Peekable(Tokenizer::tokenize($chars)), $declarations))->parseExpression();
    }

    public static function parseTyped(string $expression, Type $type, Declarations|Types|null $types = null): Expression
    {
        $expr = self::parse($expression, $types);
        return self::assertExpressionType($expr, $type, sprintf(
            'Expected parsed expression to be of type %s, got %s',
            $type,
            $expr->getType(),
        ));
    }

    private static function assertExpressionType(Expression $expr, Type $type, string $errorMessage): Expression
    {
        if ($expr->matchesType($type)) {
            return $expr;
        }
        throw TypeError::create($errorMessage, $expr->location());
    }

    private static function unexpectedToken(ParsedToken $token): never
    {
        throw SyntaxError::create(sprintf('Unexpected %s', Token::print($token->token)), $token->location());
    }

    private static function fieldAccess(Expression $target, string $name, Span $location): FieldAccess
    {
        $targetType = $target->getType();
        if (!$targetType->isStruct()) {
            throw TypeError::create(sprintf('Can\'t access field "%s" on non-struct type %s', $name, $targetType), $location);
        }
        if ($targetType->getFieldType($name) === null) {
            throw TypeError::create(sprintf('Unknown field "%s" on type %s', $name, $targetType), $location);
        }
        return Expr::fieldAccess($target, $name, $location);
    }

    private function parseExpression(): Expression
    {
        /** @var Expression | null $expr */
        $expr = null;
        while (true) {
            $newExpr = $this->parseLazy($expr);
            if ($newExpr === null) {
                break;
            }
            $expr = $newExpr;
        }
        if ($expr === null) {
            $token = $this->tokens->peek()?->token;
            throw SyntaxError::create(
                $token === null
                    ? 'Expected expression, got end of input'
                    : sprintf('Expected expression, got %s', Token::print($token)),
                self::nextSpan(),
            );
        }
        return $expr;
    }

    private function parseLazy(Expression|null $left): Expression|null
    {
        $parsedToken = $this->tokens->peek();
        if ($parsedToken === null) {
            return null;
        }
        $token = $parsedToken->token;
        if ($token === Token::Dot) {
            if ($left === null) {
                self::unexpectedToken($parsedToken);
            }
            return $this->dot($left);
        }
        if (is_string($token)) {
            if ($left !== null) {
                throw SyntaxError::create(
                    sprintf('Unexpected identifier %s', $token),
                    Span::char($parsedToken->line, $parsedToken->column),
                );
            }
            return $this->variable($token);
        }
        if ($token instanceof Literal) {
            $this->tokens->next();
            return Expr::literal($token->value, $parsedToken->location());
        }
        if ($token === Token::TripleEquals) {
            $this->tokens->next();
            if ($left === null) {
                self::unexpectedToken($parsedToken);
            }
            $right = $this->parseExpression();
            $right = self::assertExpressionType($right, $left->getType(), sprintf(
                'The expressions of both sides of === must be of the same type. Left: %s, right: %s',
                $left->getType(),
                $right->getType(),
            ));
            return $left->eq($right);
        }
        if (in_array($token, [Token::Or, Token::And], true)) {
            $this->tokens->next();
            if ($left === null) {
                self::unexpectedToken($parsedToken);
            }
            $left = self::assertExpressionType($left, Type::bool(), sprintf(
                'The expression on the left side of %s must be boolean, got %s',
                Token::print($token),
                $left->getType(),
            ));
            $right = $this->parseExpression();
            $right = self::assertExpressionType($right, Type::bool(), sprintf(
                'The expression on the right side of %s must be boolean, got %s',
                Token::print($token),
                $right->getType(),
            ));
            return $token === Token::Or ? $left->or_($right) : $left->and_($right);
        }
        if ($token === Token::Pipe) {
            return self::lambda();
        }
        if ($token === Token::Minus) {
            $this->tokens->next();
            $right = $this->parseLazy(null);
            if ($right === null) {
                throw SyntaxError::create('Unexpected end of input', Span::char($parsedToken->line, $parsedToken->column + 1));
            }
            if (!$right->matchesType(Type::int()) && !$right->matchesType(Type::float())) {
                throw TypeError::create(
                    $left === null
                        ? sprintf('Can\'t negate %s', $right->getType())
                        : sprintf('Can\'t subtract %s from %s', $right->getType(), $left->getType()),
                    $right->location(),
                );
            }
            if ($left === null) {
                return Expr::negative($right, $parsedToken->location()->to($right->location()));
            }
            if (!$left->getType()->equals($right->getType())) {
                throw TypeError::create(
                    sprintf('Can\'t subtract %s from %s', $right->getType(), $left->getType()),
                    $left->location(),
                );
            }
            return $left->subtract($right);
        }
        if ($token === Token::CloseAngle) {
            if ($left === null) {
                self::unexpectedToken($parsedToken);
            }
            $this->tokens->next();
            $right = $this->parseExpression();
            if (!$right->matchesType(Type::int()) && !$right->matchesType(Type::float())) {
                throw TypeError::create(sprintf('Can\'t compare %s to %s', $right->getType(), $left->getType()), $right->location());
            }
            if (!$left->matchesType($right->getType())) {
                throw TypeError::create(sprintf('Can\'t compare %s to %s', $left->getType(), $right->getType()), $left->location()->to($right->location()));
            }
            return $left->gt($right);
        }
        if ($token === Token::OpenBracket) {
            return self::parseListLiteral();
        }
        if ($token === Token::OpenBrace) {
            return self::parseStructLiteral();
        }
        return null;
    }

    /**
     * foo:MyClass.bar:string
     * ===========
     */
    private function variable(string $name): Get
    {
        $start = $this->expect($name);
        $declaredType = $this->declarations->variables[$name] ?? null;
        if ($this->tokens->peek()?->token !== Token::Colon) {
            if ($declaredType !== null) {
                return Expr::get($name, new TypeHint($declaredType, false), $start->location());
            }
            throw SyntaxError::create(
                sprintf('Variable %s must either be declared or have an inline type', $name),
                $start->location(),
            );
        }
        $this->expect(Token::Colon);
        $typeNode = TypeParser::parse($this->tokens);
        if ($typeNode === null) {
            throw SyntaxError::create('Expected type, got end of string', $this->nextSpan());
        }
        if ($typeNode instanceof ParsedToken) {
            throw SyntaxError::create(
                sprintf('Expected type, got %s', Token::print($typeNode->token)),
                $typeNode->location(),
            );
        }
        $type = $this->declarations->types->resolve($typeNode);
        if ($type instanceof TypeError) {
            throw $type;
        }
        if ($declaredType !== null && !$declaredType->isSubtypeOf($type)) {
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
     * @param AnyToken $expected
     */
    private function expect(Token|string|Literal $expected): ParsedToken
    {
        $actual = $this->tokens->peek();
        if ($actual === null) {
            $previousToken = $this->tokens->previous();
            assert($previousToken !== null);
            $span = Span::char($previousToken->line, $previousToken->column + 1);
            throw SyntaxError::create(sprintf('Expected %s, got end of input', Token::print($expected)), $span);
        }
        if ($actual->token === $expected) {
            $this->tokens->next();
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
     * @return list<Expression>
     */
    private function parseArgs(): array
    {
        $args = [];
        while (true) {
            $arg = $this->parseArg();
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
     */
    private function parseArg(): Expression|null
    {
        $token = $this->tokens->peek()?->token;
        if ($token === Token::CloseParen) {
            return null;
        }
        $arg = $this->parseLazy(null);
        if ($arg === null) {
            return null;
        }
        $token = $this->tokens->peek()?->token;
        if ($token === Token::Comma) {
            $this->tokens->next();
        }
        return $arg;
    }

    /**
     * |item| => item:string === needle:string
     * =======================================
     */
    private function lambda(): Expression
    {
        $start = $this->expect(Token::Pipe);
        $args = $this->parseParams();
        $this->expect(Token::Pipe);
        $body = $this->parseExpression();
        return Expr::lambda($body, $args, $start->location()->to($body->location()));
    }

    /**
     * |one, two, three, | => foo:string
     *  =================
     * @return list<string>
     */
    private function parseParams(): array
    {
        $params = [];
        while (true) {
            $param = $this->parseParam();
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
     */
    private function parseParam(): string|null
    {
        $token = $this->tokens->peek()?->token;
        if (!is_string($token)) {
            return null;
        }
        $this->tokens->next();
        if ($this->tokens->peek()?->token !== Token::Pipe) {
            self::expect(Token::Comma);
        }
        return $token;
    }

    private function dot(Expression $target): Call|FieldAccess
    {
        $dot = $this->expect(Token::Dot);
        [$name, $nameLocation] = self::expectIdentifier($dot, 'function name');
        $token = $this->tokens->peek()?->token;
        return match ($token) {
            Token::Colon, Token::OpenParen => self::call($name, $nameLocation, $target),
            default => self::fieldAccess($target, $name, $target->location()->to($nameLocation)),
        };
    }

    /**
     * list<string>.some:bool(|item| item:string === needle:string)
     *             ================================================
     */
    private function call(string $name, Span $nameLocation, Expression $target): Call
    {
        $fnType = $this->declarations->functions[$name] ?? null;
        $colonOrOpenParen = $this->tokens->peek();
        assert($colonOrOpenParen !== null);
        if ($colonOrOpenParen->token === Token::Colon) {
            $this->tokens->next();
            $typeNode = TypeParser::parse($this->tokens);
            if ($typeNode === null) {
                throw SyntaxError::create('Expected type after colon', $this->nextSpan());
            }
            if ($typeNode instanceof ParsedToken) {
                throw SyntaxError::create(
                    sprintf('Expected type after colon, got %s', Token::print($typeNode->token)),
                    $typeNode->location(),
                );
            }
            $returnType = $this->declarations->types->resolve($typeNode);
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
        self::expect(Token::OpenParen);
        $args = self::parseArgs();
        $closeParen = self::expect(Token::CloseParen);
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
     * @return array{string, Span}
     */
    private function expectIdentifier(
        ParsedToken $lastToken,
        string $expected = 'identifier',
    ): array {
        $name = $this->tokens->peek();
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
        $this->tokens->next();
        return [$name->token, $name->location()];
    }

    private function nextSpan(): Span
    {
        $token = $this->tokens->peek();
        if ($token !== null) {
            return Span::char($token->line, $token->column);
        }
        $previous = $this->tokens->previous();
        return $previous === null ? Span::char(1, 1) : Span::char($previous->line, $previous->column + 1);
    }

    private function parseListLiteral(): ListLiteral
    {
        $start = self::expect(Token::OpenBracket);
        $items = [];
        while (true) {
            $item = $this->parseLazy(null);
            if ($item === null) {
                break;
            }
            $items[] = $item;
            if ($this->tokens->peek()?->token === Token::Comma) {
                $this->tokens->next();
            }
        }
        $close = self::expect(Token::CloseBracket);
        return Expr::listLiteral($items, $start->location()->to($close->location()));
    }

    private function parseStructLiteral(): StructLiteral
    {
        $start = self::expect(Token::OpenBrace);
        $fields = [];
        while (true) {
            $field = $this->parseStructField();
            if ($field === null) {
                break;
            }
            $fields[$field[0]] = $field[1];
            $comma = $this->tokens->peek();
            if ($comma?->token !== Token::Comma) {
                break;
            }
            $this->tokens->next();
        }
        $close = self::expect(Token::CloseBrace);
        return Expr::structLiteral($fields, $start->location()->to($close->location()));
    }

    /**
     * @return array{string, Expression} | null
     */
    private function parseStructField(): array|null
    {
        $name = $this->tokens->peek();
        if ($name === null) {
            return null;
        }
        if (!is_string($name->token)) {
            return null;
        }
        $this->tokens->next();
        self::expect(Token::Colon);
        $value = $this->parseLazy(null);
        if ($value === null) {
            throw SyntaxError::create('Expected value after colon', self::nextSpan());
        }
        return [$name->token, $value];
    }
}
