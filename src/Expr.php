<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Eventjet\Ausdruck\Parser\Span;
use Eventjet\Ausdruck\Parser\TypeError;
use Eventjet\Ausdruck\Parser\TypeHint;

use function array_slice;
use function count;
use function sprintf;

/**
 * The only place expression nodes are constructed, and therefore the only place their operand types are checked. Both
 * the parser and the builder API on {@see Expression} go through here, so an expression that exists is an expression
 * whose operands type-check. Anything that reads a value from a {@see Scope} ({@see Get}, {@see Call}) asserts that
 * value against its declared type, so the guarantee survives evaluation too.
 *
 * The nodes therefore don't re-check their operands when they evaluate. They only narrow the mixed they get back from
 * their sub-expressions, via {@see Operand}, to the type PHP needs to apply the operator.
 *
 * The one operand we can't always check is a call's receiver and arguments: that needs the function's signature, and
 * only a *declared* function has one. {@see self::call()} therefore takes the signature it should check against, and
 * null means there is nothing to check against, not that the check was skipped. See its docblock.
 *
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class Expr
{
    /**
     * @psalm-suppress UnusedConstructor
     */
    private function __construct()
    {
    }

    public static function eq(Expression $left, Expression $right): Eq
    {
        if (!$right->matchesType($left->getType())) {
            throw TypeError::create(
                sprintf(
                    'The expressions of both sides of === must be of the same type. Left: %s, right: %s',
                    $left->getType(),
                    $right->getType(),
                ),
                $right->location(),
            );
        }
        return new Eq($left, $right);
    }

    public static function get(string $name, TypeHint|Type $type, Span|null $location = null): Get
    {
        return new Get($name, $type, $location ?? self::dummySpan());
    }

    /**
     * @param string | int | float | bool | null | array<array-key, mixed> $value
     */
    public static function literal(mixed $value, Span|null $location = null): Literal
    {
        return new Literal($value, $location ?? self::dummySpan());
    }

    /**
     * @param list<Expression> $elements
     */
    public static function listLiteral(array $elements, Span $location): ListLiteral
    {
        return new ListLiteral($elements, $location);
    }

    /**
     * @param array<string, Expression> $fields
     */
    public static function structLiteral(array $fields, Span $location): StructLiteral
    {
        return new StructLiteral($fields, $location);
    }

    /**
     * @param Type $returnType What the call evaluates to: the inline return type if it was annotated, the declared one
     *     otherwise. {@see Call::evaluate()} asserts the function's actual return value against it.
     * @param list<Expression> $arguments
     * @param Type|null $signature The declared type of the function, or null if it has no declaration. Only a
     *     declaration says which receiver and arguments a function accepts, so a call to an undeclared function has
     *     nothing to check its operands against. That's the case for functions that are used with nothing but an
     *     inline return type, and for every call built through {@see Expression::call()}, which has no declarations to
     *     consult.
     */
    public static function call(
        Expression $target,
        string $name,
        Type $returnType,
        array $arguments,
        Type|null $signature,
        Span|null $location = null,
    ): Call {
        $location ??= self::dummySpan();
        if ($signature !== null) {
            self::checkReceiver($target, $name, $signature);
            self::checkArguments($arguments, $name, $signature, $location);
        }
        return new Call($target, $name, $returnType, $arguments, $location);
    }

    public static function or_(Expression $left, Expression $right): Or_
    {
        return new Or_(self::assertBoolean($left, '||', 'left'), self::assertBoolean($right, '||', 'right'));
    }

    public static function and_(Expression $left, Expression $right): And_
    {
        return new And_(self::assertBoolean($left, '&&', 'left'), self::assertBoolean($right, '&&', 'right'));
    }

    /**
     * @param list<string> $parameters
     */
    public static function lambda(Expression $body, array $parameters = [], Span|null $location = null): Expression
    {
        /** @infection-ignore-all We currently don't have a way to test this */
        $location ??= self::dummySpan();
        return new Lambda($body, $parameters, $location);
    }

    public static function subtract(Expression $minuend, Expression $subtrahend): Subtract
    {
        // Which operand is at fault doesn't change how we name the mistake, only which one we point at.
        self::assertSameNumberType($minuend, $subtrahend, static fn(): string => sprintf(
            'Can\'t subtract %s from %s',
            $subtrahend->getType(),
            $minuend->getType(),
        ));
        return new Subtract($minuend, $subtrahend);
    }

    public static function gt(Expression $left, Expression $right): Gt
    {
        self::assertSameNumberType($left, $right, static fn(Expression $bad, Expression $good): string => sprintf(
            'Can\'t compare %s to %s',
            $bad->getType(),
            $good->getType(),
        ));
        return new Gt($left, $right);
    }

    public static function negative(Expression $expression, Span|null $location = null): Negative
    {
        if (!self::isNumeric($expression)) {
            throw TypeError::create(
                sprintf('Can\'t negate %s', $expression->getType()),
                $expression->location(),
            );
        }
        return new Negative($expression, $location ?? self::dummySpan());
    }

    public static function fieldAccess(Expression $struct, string $field, Span $location): FieldAccess
    {
        $structType = $struct->getType();
        if (!$structType->isStruct()) {
            throw TypeError::create(
                sprintf('Can\'t access field "%s" on non-struct type %s', $field, $structType),
                $location,
            );
        }
        $fieldType = $structType->getFieldType($field);
        if ($fieldType === null) {
            throw TypeError::create(sprintf('Unknown field "%s" on type %s', $field, $structType), $location);
        }
        return new FieldAccess($struct, $field, $fieldType, $location);
    }

    /**
     * int and float are the only types the arithmetic and ordering operators accept. Note that this has nothing to do
     * with PHP's is_numeric(): a numeric string is a string.
     */
    private static function isNumeric(Expression $expr): bool
    {
        $type = $expr->getType();
        return $type->equals(Type::int()) || $type->equals(Type::float());
    }

    /**
     * The rule every arithmetic and ordering operator follows: both operands have to be numbers, and they have to be
     * the same number type. The error points at whichever operand breaks it, so that operators that enforce the same
     * rule can't drift into blaming different parts of the expression for the same mistake.
     *
     * @param callable(Expression, Expression): string $message Called with the offending operand first and the one it
     *     was measured against second, for operators whose wording depends on which is which.
     */
    private static function assertSameNumberType(Expression $left, Expression $right, callable $message): void
    {
        if (!self::isNumeric($right)) {
            throw TypeError::create($message($right, $left), $right->location());
        }
        if (!$left->matchesType($right->getType())) {
            throw TypeError::create($message($left, $right), $left->location());
        }
    }

    /**
     * A receiver function takes the expression it's called on as its first argument: `substr` is declared as
     * func(string, [string, int, int]) and called as `foo:string.substr:string(0, 3)`, so `foo` has to be a string.
     */
    private static function checkReceiver(Expression $target, string $name, Type $signature): void
    {
        $receiverType = $signature->args[1] ?? null;
        if ($receiverType === null) {
            throw TypeError::create(
                sprintf('%s can\'t be used as a receiver function because it doesn\'t accept any arguments', $name),
                $target->location(),
            );
        }
        if ($target->isSubtypeOf($receiverType)) {
            return;
        }
        throw TypeError::create(
            sprintf(
                '%s must be called on an expression of type %s, but %s is of type %s',
                $name,
                $receiverType,
                $target,
                $target->getType(),
            ),
            $target->location(),
        );
    }

    /**
     * @param list<Expression> $arguments
     * @param Span $location The location of the whole call, which is the best we can do to point at an argument that
     *     isn't there.
     */
    private static function checkArguments(array $arguments, string $name, Type $signature, Span $location): void
    {
        // What's left after dropping the return type and the receiver type are the parameters that are passed in
        // parentheses.
        $parameterTypes = array_slice($signature->args, 2);
        foreach ($parameterTypes as $index => $parameterType) {
            $argument = $arguments[$index] ?? null;
            if ($argument === null) {
                throw TypeError::create(
                    sprintf('%s expects %d arguments, got %d', $name, count($parameterTypes), count($arguments)),
                    $location,
                );
            }
            if ($argument->isSubtypeOf($parameterType)) {
                continue;
            }
            throw TypeError::create(
                sprintf(
                    'Argument %d of %s must be of type %s, got %s',
                    $index + 1,
                    $name,
                    $parameterType,
                    $argument->getType(),
                ),
                $argument->location(),
            );
        }
    }

    /**
     * @param 'left' | 'right' $side
     */
    private static function assertBoolean(Expression $expr, string $operator, string $side): Expression
    {
        if ($expr->matchesType(Type::bool())) {
            return $expr;
        }
        throw TypeError::create(
            sprintf(
                'The expression on the %s side of %s must be boolean, got %s',
                $side,
                $operator,
                $expr->getType(),
            ),
            $expr->location(),
        );
    }

    private static function dummySpan(): Span
    {
        /** @infection-ignore-all These dummy spans are just there to fill parameter lists */
        return Span::char(1, 1);
    }
}
