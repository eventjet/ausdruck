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

use function is_string;
use function sprintf;

/**
 * Expressions are parsed as a cascade of precedence levels, from loosest to tightest binding:
 *
 *     expression → or → and → comparison → additive → unary → postfix → primary
 *
 * Each level consumes only its own operators and delegates to the next tighter level for its operands. Precedence and
 * associativity are therefore expressed by the call graph, and a level never needs to know which operators sit above
 * it. Anywhere a full expression is expected—a lambda body, a call argument, a list item, a struct field value, a
 * parenthesized group—call {@see self::parseExpression()} rather than one of the levels, so that operators are allowed
 * there too. A parenthesized group is the one such place that is itself an operand: it re-enters the cascade at the
 * top from {@see self::parsePrimary()}, its tightest level, which is what lets parentheses override precedence.
 *
 * @api
 */
final class ExpressionParser
{
    /**
     * The reader of the types written inside an expression, over the same stream this one reads: a `:int` is read by
     * handing the stream over and carrying on where it left off.
     */
    private readonly TypeParser $typeParser;

    private function __construct(private readonly Tokens $tokens, private readonly Declarations $declarations)
    {
        $this->typeParser = new TypeParser($tokens);
    }

    public static function parse(string $expression, Declarations|Types|null $types = null): Expression
    {
        if ($types === null) {
            $types = new Types();
        }
        if ($types instanceof Types) {
            $types = new Declarations($types);
        }
        return (new self(Tokens::of($expression), $types))->parseComplete();
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
     * make `42 "foo"` — two expressions with nothing joining them — a roundabout way of writing `42`.
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
        while ($this->tokens->peekToken() === Token::Or) {
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
        while ($this->tokens->peekToken() === Token::And) {
            $this->tokens->next();
            $left = $left->and_($this->parseComparison());
        }
        return $left;
    }

    /**
     * foo:int - 1 > bar:int && baz:bool
     * ====================
     *
     * The comparison operators (===, !==, >, <, >=, <=) are non-associative: a === b === c is a syntax error rather
     * than (a === b) === c, which could only ever be a type error anyway. `<` and `>` double as the angle brackets of a
     * generic type; a bare `<` reaches here as less-than only once {@see TypeParser} has declined to read it as the
     * start of a type argument list.
     */
    private function parseComparison(): Expression
    {
        $left = $this->parseAdditive();
        $build = match ($this->tokens->peekToken()) {
            Token::TripleEquals => $left->eq(...),
            Token::NotEquals => $left->neq(...),
            Token::CloseAngle => $left->gt(...),
            Token::OpenAngle => $left->lt(...),
            Token::GreaterThanEquals => $left->gte(...),
            Token::LessThanEquals => $left->lte(...),
            default => null,
        };
        if ($build === null) {
            return $left;
        }
        $this->tokens->next();
        return $build($this->parseAdditive());
    }

    /**
     * a:int - b:int - c:int
     * =====================
     */
    private function parseAdditive(): Expression
    {
        $left = $this->parseUnary();
        while ($this->tokens->peekToken() === Token::Minus) {
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
        while ($this->tokens->peekToken() === Token::Dot) {
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
            throw $this->tokens->expected('expression');
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
        if ($token === Token::OpenParen) {
            return $this->group();
        }
        throw $this->tokens->expected('expression');
    }

    /**
     * (a:bool || b:bool) && c:bool
     * ==================
     *
     * Grouping adds no node of its own: it starts the cascade over, so the group is a whole expression whatever
     * surrounds it, and the tree it produces is the same one the grouped operators would build at the top level. Which
     * grouping overrode the default precedence is recovered by {@see Precedence}, not stored here.
     */
    private function group(): Expression
    {
        $this->tokens->expect(Token::OpenParen);
        $inner = $this->parseExpression();
        $this->tokens->expect(Token::CloseParen);
        return $inner;
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
        if ($this->tokens->peekToken() !== Token::Colon) {
            if ($declaredType !== null) {
                return Expr::get($name, new TypeHint($declaredType, false), $start);
            }
            throw SyntaxError::create(
                sprintf('Variable %s must either be declared or have an inline type', $name),
                $start,
            );
        }
        $this->tokens->expect(Token::Colon);
        $typeNode = $this->typeParser->type();
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

    /**
     * |one, two, three, | item:string === needle:string
     * =================================================
     */
    private function lambda(): Expression
    {
        $start = $this->tokens->expect(Token::Pipe);
        $params = $this->tokens->commaSeparated(
            Token::Pipe,
            fn(): string => $this->tokens->expectIdentifier('parameter name')[0],
        );
        $this->tokens->expect(Token::Pipe);
        $body = $this->parseExpression();
        return Expr::lambda($body, $params, $start->location()->to($body->location()));
    }

    private function dot(Expression $target): Call|FieldAccess
    {
        $this->tokens->expect(Token::Dot);
        [$name, $nameLocation] = $this->tokens->expectIdentifier('function name');
        $token = $this->tokens->peekToken();
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
        $this->tokens->expect(Token::OpenParen);
        $args = $this->tokens->commaSeparated(Token::CloseParen, $this->parseExpression(...));
        $closeParen = $this->tokens->expect(Token::CloseParen);
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
        if ($this->tokens->peekToken() !== Token::Colon) {
            return null;
        }
        $this->tokens->expect(Token::Colon);
        $typeNode = $this->typeParser->type('return type');
        $returnType = $this->declarations->types->resolve($typeNode);
        if ($returnType instanceof TypeError) {
            throw $returnType;
        }
        return new TypeAnnotation($returnType, $typeNode->location);
    }

    /**
     * [1 - 2, foo:int]
     * ================
     */
    private function parseListLiteral(): ListLiteral
    {
        $start = $this->tokens->expect(Token::OpenBracket);
        $items = $this->tokens->commaSeparated(Token::CloseBracket, $this->parseExpression(...));
        $close = $this->tokens->expect(Token::CloseBracket);
        return Expr::listLiteral($items, $start->location()->to($close->location()));
    }

    /**
     * {name: "John", age: 42 - 1}
     * ===========================
     */
    private function parseStructLiteral(): StructLiteral
    {
        $start = $this->tokens->expect(Token::OpenBrace);
        $fields = [];
        $parsed = $this->tokens->commaSeparated(Token::CloseBrace, $this->parseStructField(...));
        foreach ($parsed as [$name, $value]) {
            $fields[$name] = $value;
        }
        $close = $this->tokens->expect(Token::CloseBrace);
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
        [$name] = $this->tokens->expectIdentifier('field name');
        $this->tokens->expect(Token::Colon);
        return [$name, $this->parseExpression()];
    }
}
