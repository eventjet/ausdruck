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
use function is_float;
use function is_int;
use function is_string;
use function sprintf;
use function str_split;

/**
 * Expressions are parsed as a cascade of precedence levels, from loosest to tightest binding:
 *
 *     expression → or → and → comparison → additive → unary → postfix → primary
 *
 * Each level consumes only its own operators and delegates to the next tighter level for its operands. Precedence and
 * associativity are therefore expressed by the call graph, and a level never needs to know which operators sit above
 * it. Anywhere a full expression is expected—a lambda body, a call argument, a list item, a struct field value—call
 * {@see self::parseExpression()} rather than one of the levels, so that operators are allowed there too.
 *
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
        return (new self(new Peekable(Tokenizer::tokenize($chars)), $declarations))->parseComplete();
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
        throw SyntaxError::create(
            is_string($token->token)
                ? sprintf('Unexpected identifier %s', $token->token)
                : sprintf('Unexpected %s', Token::print($token->token)),
            $token->location(),
        );
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

    /**
     * @param 'left' | 'right' $side
     */
    private static function assertBooleanOperand(Expression $expr, Token $operator, string $side): Expression
    {
        return self::assertExpressionType($expr, Type::bool(), sprintf(
            'The expression on the %s side of %s must be boolean, got %s',
            $side,
            Token::print($operator),
            $expr->getType(),
        ));
    }

    private function parseComplete(): Expression
    {
        $expression = $this->parseExpression();
        $trailing = $this->tokens->peek();
        if ($trailing !== null) {
            self::unexpectedToken($trailing);
        }
        return $expression;
    }

    private function parseExpression(): Expression
    {
        return $this->parseOr();
    }

    /**
     * a:bool && b:bool || c:bool
     * ==========================
     */
    private function parseOr(): Expression
    {
        $left = $this->parseAnd();
        while ($this->nextToken() === Token::Or) {
            $this->tokens->next();
            $left = self::assertBooleanOperand($left, Token::Or, 'left');
            $right = self::assertBooleanOperand($this->parseAnd(), Token::Or, 'right');
            $left = $left->or_($right);
        }
        return $left;
    }

    /**
     * a:bool && b:bool || c:bool
     * ================
     */
    private function parseAnd(): Expression
    {
        $left = $this->parseComparison();
        while ($this->nextToken() === Token::And) {
            $this->tokens->next();
            $left = self::assertBooleanOperand($left, Token::And, 'left');
            $right = self::assertBooleanOperand($this->parseComparison(), Token::And, 'right');
            $left = $left->and_($right);
        }
        return $left;
    }

    /**
     * foo:int - 1 > bar:int && baz:bool
     * ====================
     *
     * === and > are non-associative: a === b === c is a syntax error rather than (a === b) === c, which could only ever
     * be a type error anyway.
     */
    private function parseComparison(): Expression
    {
        $left = $this->parseAdditive();
        $operator = $this->nextToken();
        if ($operator === Token::TripleEquals) {
            $this->tokens->next();
            $right = $this->parseAdditive();
            $right = self::assertExpressionType($right, $left->getType(), sprintf(
                'The expressions of both sides of === must be of the same type. Left: %s, right: %s',
                $left->getType(),
                $right->getType(),
            ));
            return $left->eq($right);
        }
        if ($operator === Token::CloseAngle) {
            $this->tokens->next();
            $right = $this->parseAdditive();
            if (!$right->matchesType(Type::int()) && !$right->matchesType(Type::float())) {
                throw TypeError::create(
                    sprintf('Can\'t compare %s to %s', $right->getType(), $left->getType()),
                    $right->location(),
                );
            }
            if (!$left->matchesType($right->getType())) {
                throw TypeError::create(
                    sprintf('Can\'t compare %s to %s', $left->getType(), $right->getType()),
                    $left->location()->to($right->location()),
                );
            }
            return $left->gt($right);
        }
        return $left;
    }

    /**
     * a:int - b:int - c:int
     * =====================
     */
    private function parseAdditive(): Expression
    {
        $left = $this->parseUnary();
        while ($this->nextToken() === Token::Minus) {
            $this->tokens->next();
            $right = $this->parseUnary();
            if (!$right->matchesType(Type::int()) && !$right->matchesType(Type::float())) {
                throw TypeError::create(
                    sprintf('Can\'t subtract %s from %s', $right->getType(), $left->getType()),
                    $right->location(),
                );
            }
            if (!$left->getType()->equals($right->getType())) {
                throw TypeError::create(
                    sprintf('Can\'t subtract %s from %s', $right->getType(), $left->getType()),
                    $left->location(),
                );
            }
            $left = $left->subtract($right);
        }
        return $left;
    }

    /**
     * -foo:int
     * ========
     *
     * A minus in front of a number literal folds into a negative literal, so that -69 stays a literal rather than
     * becoming a negation of 69. The tokenizer deliberately doesn't do this, because there it couldn't tell the
     * negative literal in `[-2]` apart from the subtraction in `a -2`.
     */
    private function parseUnary(): Expression
    {
        $minus = $this->tokens->peek();
        if ($minus === null || $minus->token !== Token::Minus) {
            return $this->parsePostfix();
        }
        $this->tokens->next();
        $number = $this->tokens->peek();
        $literal = $number?->token;
        if ($number !== null && $literal instanceof Literal) {
            $value = $literal->value;
            if (is_int($value) || is_float($value)) {
                $this->tokens->next();
                return Expr::literal(-$value, $minus->location()->to($number->location()));
            }
        }
        $operand = $this->parseUnary();
        if (!$operand->matchesType(Type::int()) && !$operand->matchesType(Type::float())) {
            throw TypeError::create(sprintf('Can\'t negate %s', $operand->getType()), $operand->location());
        }
        return Expr::negative($operand, $minus->location()->to($operand->location()));
    }

    /**
     * foo:MyClass.bar.baz:string.substr:string(0, 3)
     * ==============================================
     */
    private function parsePostfix(): Expression
    {
        $expr = $this->parsePrimary();
        while ($this->nextToken() === Token::Dot) {
            $expr = $this->dot($expr);
        }
        return $expr;
    }

    /**
     * The tightest level: anything that can start an expression and needs no left-hand operand.
     */
    private function parsePrimary(): Expression
    {
        $parsedToken = $this->tokens->peek();
        if ($parsedToken === null) {
            throw SyntaxError::create('Expected expression, got end of input', $this->nextSpan());
        }
        $token = $parsedToken->token;
        if ($token === 'true') {
            $this->tokens->next();
            return Expr::literal(true, $parsedToken->location());
        }
        if ($token === 'false') {
            $this->tokens->next();
            return Expr::literal(false, $parsedToken->location());
        }
        if (is_string($token)) {
            return $this->variable($token);
        }
        if ($token instanceof Literal) {
            $this->tokens->next();
            return Expr::literal($token->value, $parsedToken->location());
        }
        if ($token === Token::Pipe) {
            return $this->lambda();
        }
        if ($token === Token::OpenBracket) {
            return $this->parseListLiteral();
        }
        if ($token === Token::OpenBrace) {
            return $this->parseStructLiteral();
        }
        throw SyntaxError::create(
            sprintf('Expected expression, got %s', Token::print($token)),
            $parsedToken->location(),
        );
    }

    /**
     * foo:MyClass.bar:string
     * ===========
     */
    private function variable(string $name): Get
    {
        $start = $this->expect($name);
        $declaredType = $this->declarations->variables[$name] ?? null;
        if ($this->nextToken() !== Token::Colon) {
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
     * The body of every bracketed, comma-separated list in the language: call arguments, list items, struct fields and
     * lambda parameters.
     *
     * A trailing comma is allowed. A missing one simply ends the list, which leaves the caller's expect($close) to
     * report the token that isn't a comma.
     *
     * @template T
     * @param callable(): T $parseItem
     * @return list<T>
     */
    private function parseCommaSeparated(Token $close, callable $parseItem): array
    {
        $items = [];
        while (true) {
            $token = $this->nextToken();
            if ($token === null || $token === $close) {
                return $items;
            }
            $items[] = $parseItem();
            if ($this->nextToken() !== Token::Comma) {
                return $items;
            }
            $this->tokens->next();
        }
    }

    /**
     * |one, two, three, | item:string === needle:string
     * =================================================
     */
    private function lambda(): Expression
    {
        $start = $this->expect(Token::Pipe);
        $params = $this->parseCommaSeparated(
            Token::Pipe,
            fn(): string => $this->expectIdentifier('parameter name')[0],
        );
        $this->expect(Token::Pipe);
        $body = $this->parseExpression();
        return Expr::lambda($body, $params, $start->location()->to($body->location()));
    }

    private function dot(Expression $target): Call|FieldAccess
    {
        $this->expect(Token::Dot);
        [$name, $nameLocation] = $this->expectIdentifier('function name');
        $token = $this->nextToken();
        return match ($token) {
            Token::Colon, Token::OpenParen => $this->call($name, $nameLocation, $target),
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
        $this->expect(Token::OpenParen);
        $args = $this->parseCommaSeparated(Token::CloseParen, $this->parseExpression(...));
        $closeParen = $this->expect(Token::CloseParen);
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
    private function expectIdentifier(string $expected): array
    {
        $name = $this->tokens->peek();
        if ($name === null) {
            throw SyntaxError::create(sprintf('Expected %s, got end of input', $expected), $this->nextSpan());
        }
        if (!is_string($name->token)) {
            throw SyntaxError::create(
                sprintf('Expected %s, got %s', $expected, Token::print($name->token)),
                $name->location(),
            );
        }
        $this->tokens->next();
        return [$name->token, $name->location()];
    }

    /**
     * The token the parser is looking at, or null at the end of the input.
     *
     * @return Token | string | Literal<string | int | float | bool> | null
     */
    private function nextToken(): Token|string|Literal|null
    {
        return $this->tokens->peek()?->token;
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

    /**
     * [1 - 2, foo:int]
     * ================
     */
    private function parseListLiteral(): ListLiteral
    {
        $start = $this->expect(Token::OpenBracket);
        $items = $this->parseCommaSeparated(Token::CloseBracket, $this->parseExpression(...));
        $close = $this->expect(Token::CloseBracket);
        return Expr::listLiteral($items, $start->location()->to($close->location()));
    }

    /**
     * {name: "John", age: 42 - 1}
     * ===========================
     */
    private function parseStructLiteral(): StructLiteral
    {
        $start = $this->expect(Token::OpenBrace);
        $fields = [];
        foreach ($this->parseCommaSeparated(Token::CloseBrace, $this->parseStructField(...)) as [$name, $value]) {
            $fields[$name] = $value;
        }
        $close = $this->expect(Token::CloseBrace);
        return Expr::structLiteral($fields, $start->location()->to($close->location()));
    }

    /**
     * {name: "John"}
     *  ============
     *
     * @return array{string, Expression}
     */
    private function parseStructField(): array
    {
        [$name] = $this->expectIdentifier('field name');
        $this->expect(Token::Colon);
        return [$name, $this->parseExpression()];
    }
}
