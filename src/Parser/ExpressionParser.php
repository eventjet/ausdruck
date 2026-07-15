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

use function assert;
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
        if ($expr->matchesType($type)) {
            return $expr;
        }
        throw TypeError::create(
            sprintf('Expected parsed expression to be of type %s, got %s', $type, $expr->getType()),
            $expr->location(),
        );
    }

    /**
     * The entire input has to be a single expression. Stopping at the first complete one and dropping the rest would
     * make `42 < 23` — a comparison the language doesn't have — a roundabout way of writing `42`.
     */
    private function parseComplete(): Expression
    {
        $expression = $this->parseExpression();
        $trailing = $this->tokens->peek();
        if ($trailing !== null) {
            throw SyntaxError::unexpectedToken($trailing);
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
            $left = $left->or_($this->parseAnd());
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
            $left = $left->and_($this->parseComparison());
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
            return $left->eq($this->parseAdditive());
        }
        if ($operator === Token::CloseAngle) {
            $this->tokens->next();
            return $left->gt($this->parseAdditive());
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
            $left = $left->subtract($this->parseUnary());
        }
        return $left;
    }

    /**
     * -foo:int
     * ========
     *
     * Negating a number literal produces a negative literal rather than a negation of a positive one, but that's
     * {@see Expr::negative()}'s job, not ours: folding it here would mean returning a primary without going through
     * parsePostfix(), and `-2 .abs:int()` would stop parsing.
     */
    private function parseUnary(): Expression
    {
        $minus = $this->tokens->peek();
        if ($minus === null || $minus->token !== Token::Minus) {
            return $this->parsePostfix();
        }
        $this->tokens->next();
        $operand = $this->parseUnary();
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
            $this->tokens->next();
            return $this->variable($token, $parsedToken->location());
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
     *
     * @param Span $start The location of the name, which {@see self::parsePrimary()} has already consumed.
     */
    private function variable(string $name, Span $start): Get
    {
        $declaredType = $this->declarations->variables[$name] ?? null;
        if ($this->nextToken() !== Token::Colon) {
            if ($declaredType !== null) {
                return Expr::get($name, new TypeHint($declaredType, false), $start);
            }
            throw SyntaxError::create(
                sprintf('Variable %s must either be declared or have an inline type', $name),
                $start,
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
                $start->to($typeNode->location),
            );
        }
        return Expr::get($name, $type, $start->to($typeNode->location));
    }

    private function expect(Token $expected): ParsedToken
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
            default => Expr::fieldAccess($target, $name, $target->location()->to($nameLocation)),
        };
    }

    /**
     * list<string>.some:bool(|item| item:string === needle:string)
     *             ================================================
     */
    private function call(string $name, Span $nameLocation, Expression $target): Call
    {
        $signature = $this->declarations->functions[$name] ?? null;
        $returnType = $this->returnType();
        $this->expect(Token::OpenParen);
        $args = $this->parseCommaSeparated(Token::CloseParen, $this->parseExpression(...));
        $closeParen = $this->expect(Token::CloseParen);
        return Expr::call(
            $target,
            $name,
            $returnType,
            $args,
            $signature,
            $nameLocation,
            $target->location()->to($closeParen->location()),
        );
    }

    /**
     * foo:string.substr:string(0, 3)
     *                  ======
     *
     * Only whether the call site spells a return type out, and which one. Whether it's allowed to leave it out, and
     * whether the one it spells out fits the function's declaration, is {@see Expr::call()}'s business: those rules hold
     * for a call however it was built, and a call built through {@see Expression::call()} never comes past here.
     */
    private function returnType(): TypeAnnotation|null
    {
        if ($this->nextToken() !== Token::Colon) {
            return null;
        }
        $this->expect(Token::Colon);
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
        return new TypeAnnotation($returnType, $typeNode->location);
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
