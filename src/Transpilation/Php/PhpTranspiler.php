<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Transpilation\Php;

use Eventjet\Ausdruck\Call;
use Eventjet\Ausdruck\Eq;
use Eventjet\Ausdruck\Expression;
use Eventjet\Ausdruck\Get;
use Eventjet\Ausdruck\Gt;
use Eventjet\Ausdruck\Lambda;
use Eventjet\Ausdruck\ListLiteral;
use Eventjet\Ausdruck\Literal;
use Eventjet\Ausdruck\Negative;
use Eventjet\Ausdruck\Or_;
use Eventjet\Ausdruck\Subtract;
use LogicException;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\BinaryOp\Greater;
use PhpParser\Node\Expr\BinaryOp\Identical;
use PhpParser\Node\Expr\BinaryOp\LogicalOr;
use PhpParser\Node\Expr\BinaryOp\Minus;
use PhpParser\Node\Expr\BinaryOp\NotIdentical;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\UnaryMinus;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar\DNumber;
use PhpParser\Node\Scalar\LNumber;
use PhpParser\Node\Scalar\String_;

use function array_map;
use function is_float;
use function is_int;
use function is_string;
use function sprintf;

final class PhpTranspiler
{
    public static function transpile(Expression $expression): Expr
    {
        return match ($expression::class) {
            Call::class => self::transpileCall($expression),
            Eq::class => self::transpileEq($expression),
            Get::class => self::transpileGet($expression),
            Gt::class => self::transpileGt($expression),
            Lambda::class => self::transpileLambda($expression),
            ListLiteral::class => self::transpileListLiteral($expression),
            Literal::class => self::transpileLiteral($expression),
            Negative::class => self::transpileNegative($expression),
            Or_::class => self::transpileOr($expression),
            Subtract::class => self::transpileSubtract($expression),
            default => throw new LogicException(
                sprintf('Transpilation of %s expressions is not implemented', $expression::class),
            ),
        };
    }

    private static function transpileGet(Get $expression): Expr
    {
        return new Variable($expression->name);
    }

    private static function transpileEq(Eq $expression): Expr
    {
        return new Identical(
            self::transpile($expression->left),
            self::transpile($expression->right),
        );
    }

    private static function transpileLiteral(Literal $expression): Expr
    {
        if (is_string($expression->value)) {
            return new String_($expression->value);
        }
        if (is_int($expression->value)) {
            return new LNumber($expression->value);
        }
        if (is_float($expression->value)) {
            return new DNumber($expression->value);
        }
        throw new LogicException('Transpilation of non-string, non-int literals is not implemented');
    }

    private static function transpileSubtract(Subtract $expression): Expr
    {
        return new Minus(
            self::transpile($expression->minuend),
            self::transpile($expression->subtrahend),
        );
    }

    private static function transpileNegative(Negative $expression): Expr
    {
        return new UnaryMinus(self::transpile($expression->expression));
    }

    private static function transpileCall(Call $expression): Expr
    {
        if ($expression->name === 'isSome') {
            return new NotIdentical(
                self::transpile($expression->target),
                new Variable('null'),
            );
        }
        $name = match ($expression->name) {
            'contains' => 'in_array',
            'filter' => 'array_filter',
            'map' => 'array_map',
            'take' => 'array_slice',
            'unique' => 'array_unique',
            default => $expression->name,
        };
        $arguments = [
            new Arg(self::transpile($expression->target)),
            ...array_map(
                static fn(Expression $argument) => new Arg(self::transpile($argument)),
                $expression->arguments,
            ),
        ];
        $arguments = match ($expression->name) {
            'contains' => [$arguments[1], $arguments[0], new Arg(new ConstFetch(new Name('true')))],
            'map' => [$arguments[1], $arguments[0]],
            'take' => [$arguments[0], new Arg(new LNumber(0)), $arguments[1]],
            default => $arguments,
        };
        return new FuncCall(new Name($name), $arguments);
    }

    private static function transpileLambda(Lambda $expression): Expr
    {
        return new ArrowFunction([
            'static' => true,
            'expr' => self::transpile($expression->body),
            'params' => array_map(
                static fn(string $parameter) => new Param(
                    var: new Variable($parameter),
                ),
                $expression->parameters,
            ),
        ]);
    }

    private static function transpileListLiteral(ListLiteral $expression): Expr
    {
        return new Array_(
            array_map(
                static fn(Expression $item) => new ArrayItem(
                    value: self::transpile($item),
                ),
                $expression->elements,
            ),
        );
    }

    private static function transpileGt(Gt $expression): Expr
    {
        return new Greater(
            self::transpile($expression->left),
            self::transpile($expression->right),
        );
    }

    private static function transpileOr(Or_ $expression): Expr
    {
        return new LogicalOr(
            self::transpile($expression->left),
            self::transpile($expression->right),
        );
    }
}
