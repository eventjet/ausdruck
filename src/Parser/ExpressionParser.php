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
        if ($expr->matchesType($type)) {
            return $expr;
        }
        throw TypeError::create(
            sprintf('Expected parsed expression to be of type %s, got %s', $type, $expr->getType()),
            $expr->location(),
        );
    }

    private static function unexpectedToken(ParsedToken $token): never
    {
        throw SyntaxError::create(sprintf('Unexpected %s', Token::print($token->token)), $token->location());
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
                $this->nextSpan(),
            );
        }
        return $expr;
    }

    private function parseExpressionUntilLogical(): Expression
    {
        /** @var Expression | null $expr */
        $expr = null;
        while (true) {
            $peek = $this->tokens->peek();
            if ($peek !== null && in_array($peek->token, [Token::Or, Token::And], true) && $expr !== null) {
                break;
            }
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
                $this->nextSpan(),
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
        if ($token === 'true') {
            $this->tokens->next();
            return Expr::literal(true, $parsedToken->location());
        }
        if ($token === 'false') {
            $this->tokens->next();
            return Expr::literal(false, $parsedToken->location());
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
            return $left->eq($this->parseExpressionUntilLogical());
        }
        if (in_array($token, [Token::Or, Token::And], true)) {
            $this->tokens->next();
            if ($left === null) {
                self::unexpectedToken($parsedToken);
            }
            $right = $this->parseExpression();
            return $token === Token::Or ? $left->or_($right) : $left->and_($right);
        }
        if ($token === Token::Pipe) {
            return $this->lambda();
        }
        if ($token === Token::Minus) {
            $this->tokens->next();
            $right = $this->parseLazy(null);
            if ($right === null) {
                throw SyntaxError::create('Unexpected end of input', Span::char($parsedToken->line, $parsedToken->column + 1));
            }
            return $left === null
                ? Expr::negative($right, $parsedToken->location()->to($right->location()))
                : $left->subtract($right);
        }
        if ($token === Token::CloseAngle) {
            if ($left === null) {
                self::unexpectedToken($parsedToken);
            }
            $this->tokens->next();
            return $left->gt($this->parseExpressionUntilLogical());
        }
        if ($token === Token::OpenBracket) {
            return $this->parseListLiteral();
        }
        if ($token === Token::OpenBrace) {
            return $this->parseStructLiteral();
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
            $this->expect(Token::Comma);
        }
        return $token;
    }

    private function dot(Expression $target): Call|FieldAccess
    {
        $dot = $this->expect(Token::Dot);
        [$name, $nameLocation] = $this->expectIdentifier($dot, 'function name');
        $token = $this->tokens->peek()?->token;
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
        $args = $this->parseArgs();
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
        if ($this->tokens->peek()?->token !== Token::Colon) {
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
        $start = $this->expect(Token::OpenBracket);
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
        $close = $this->expect(Token::CloseBracket);
        return Expr::listLiteral($items, $start->location()->to($close->location()));
    }

    private function parseStructLiteral(): StructLiteral
    {
        $start = $this->expect(Token::OpenBrace);
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
        $close = $this->expect(Token::CloseBrace);
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
        $this->expect(Token::Colon);
        $value = $this->parseLazy(null);
        if ($value === null) {
            throw SyntaxError::create('Expected value after colon', $this->nextSpan());
        }
        return [$name->token, $value];
    }
}
