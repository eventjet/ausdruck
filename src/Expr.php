<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Eventjet\Ausdruck\Parser\Span;
use Eventjet\Ausdruck\Parser\TypeAnnotation;
use Eventjet\Ausdruck\Parser\TypeError;
use Eventjet\Ausdruck\Parser\TypeHint;

use function count;
use function sprintf;

/**
 * The only place expression nodes are constructed, and therefore the only place their types are checked. Both the parser
 * and the builder API on {@see Expression} go through here, so an expression that exists is an expression whose operands
 * type-check and whose type is the one its operands give it. Anything that reads a value from a {@see Scope}
 * ({@see Get}, {@see Call}) asserts that value against its declared type, so the guarantee survives evaluation too.
 *
 * The nodes therefore don't re-check their operands when they evaluate. They only narrow the mixed they get back from
 * their sub-expressions, via {@see Operand}, to the type PHP needs to apply the operator.
 *
 * The one node whose type doesn't follow from its operands is a {@see Call}: what a call returns is what its inline
 * annotation says, or what the function's declaration says, and only a *declared* function has a signature to check the
 * receiver and the arguments against. {@see self::call()} therefore takes both, and resolves the return type from them
 * rather than being handed one; null means there is nothing to check against, not that the check was skipped.
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
        self::checkComparison(ComparisonOperator::Equals, $left, $right);
        return new Eq($left, $right);
    }

    public static function neq(Expression $left, Expression $right): Comparison
    {
        return self::comparison(ComparisonOperator::NotEquals, $left, $right);
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
     * @param TypeAnnotation|null $returnType The return type the call site spells out, or null if it doesn't spell one
     *     out. What the call evaluates to is resolved from this and the declaration; see {@see self::returnType()}.
     * @param list<Expression> $arguments
     * @param Type|null $signature The declared type of the function, or null if it has no declaration. Only a
     *     declaration says which receiver and arguments a function accepts, so a call to an undeclared function has
     *     nothing to check its operands against. That's the case for functions that are used with nothing but an
     *     inline return type, and for every call built through {@see Expression::call()}, which has no declarations to
     *     consult.
     * @param Span|null $nameLocation Where the function is named, which is what an error about the function itself
     *     rather than about one of its operands points at.
     */
    public static function call(
        Expression $target,
        string $name,
        TypeAnnotation|null $returnType,
        array $arguments,
        Type|null $signature,
        Span|null $nameLocation = null,
        Span|null $location = null,
    ): Call {
        $location ??= self::dummySpan();
        $type = self::returnType($name, $returnType, $signature, $nameLocation ?? self::dummySpan());
        if ($signature !== null) {
            self::checkReceiver($target, $name, $signature);
            self::checkArguments($arguments, $name, $signature, $location);
        }
        return new Call($target, $name, $type, $arguments, $location);
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
        // Which operand is at fault doesn't change how we name the mistake, only which one we point at. The same goes
        // for the other arithmetic operators below.
        self::assertSameNumberType($minuend, $subtrahend, static fn(): string => sprintf(
            'Can\'t subtract %s from %s',
            $subtrahend->getType(),
            $minuend->getType(),
        ));
        return new Subtract($minuend, $subtrahend);
    }

    public static function add(Expression $augend, Expression $addend): Add
    {
        self::assertSameNumberType($augend, $addend, static fn(): string => sprintf(
            'Can\'t add %s to %s',
            $addend->getType(),
            $augend->getType(),
        ));
        return new Add($augend, $addend);
    }

    public static function gt(Expression $left, Expression $right): Gt
    {
        self::checkComparison(ComparisonOperator::GreaterThan, $left, $right);
        return new Gt($left, $right);
    }

    public static function lt(Expression $left, Expression $right): Comparison
    {
        return self::comparison(ComparisonOperator::LessThan, $left, $right);
    }

    public static function gte(Expression $left, Expression $right): Comparison
    {
        return self::comparison(ComparisonOperator::GreaterThanOrEqual, $left, $right);
    }

    public static function lte(Expression $left, Expression $right): Comparison
    {
        return self::comparison(ComparisonOperator::LessThanOrEqual, $left, $right);
    }

    /**
     * Negating a number literal folds into a negative literal, so that -69 is the literal -69 rather than a negation of
     * 69. The tokenizer deliberately doesn't fold the sign in, because there it couldn't tell the negative literal in
     * `[-2]` apart from the subtraction in `a -2`; by the time we get here, the minus is known to be a negation.
     *
     * A Negative therefore never wraps a Literal, and isNumeric() has already established that one that gets this far
     * holds an int or a float.
     */
    public static function negative(Expression $expression, Span|null $location = null): Negative|Literal
    {
        if (!self::isNumeric($expression)) {
            throw TypeError::create(
                sprintf('Can\'t negate %s', $expression->getType()),
                $expression->location(),
            );
        }
        $location ??= self::dummySpan();
        return $expression instanceof Literal
            ? new Literal(-Operand::number($expression->value()), $location)
            : new Negative($expression, $location);
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
     * The location of an expression that has none, because it was built rather than parsed. It only ever fills a
     * parameter list, or points an error at a source that isn't there.
     */
    public static function dummySpan(): Span
    {
        /** @infection-ignore-all These dummy spans are just there to fill parameter lists */
        return Span::char(1, 1);
    }

    private static function comparison(ComparisonOperator $operator, Expression $left, Expression $right): Comparison
    {
        self::checkComparison($operator, $left, $right);
        return new Comparison($operator, $left, $right);
    }

    /**
     * Which rule an operator's operands have to satisfy, for all six in one place. The two rules answer different
     * questions: `===` and `!==` compare for equality, so their operands only have to be of the same type, while the
     * four ordering operators need operands that have an order at all. Stating the split once means a seventh operator
     * has to be classified rather than copied from whichever neighbor happened to look closest.
     */
    private static function checkComparison(ComparisonOperator $operator, Expression $left, Expression $right): void
    {
        match ($operator) {
            ComparisonOperator::Equals,
            ComparisonOperator::NotEquals => self::assertSameType($left, $right, $operator->token()->value),
            ComparisonOperator::GreaterThan,
            ComparisonOperator::LessThan,
            ComparisonOperator::GreaterThanOrEqual,
            ComparisonOperator::LessThanOrEqual => self::assertComparable($left, $right),
        };
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
     * The rule both `===` and `!==` follow: the two sides have to be of the same type, whatever that type is. The error
     * points at the right-hand side, the one measured against the left.
     */
    private static function assertSameType(Expression $left, Expression $right, string $operator): void
    {
        if ($right->matchesType($left->getType())) {
            return;
        }
        throw TypeError::create(
            sprintf(
                'The expressions of both sides of %s must be of the same type. Left: %s, right: %s',
                $operator,
                $left->getType(),
                $right->getType(),
            ),
            $right->location(),
        );
    }

    /**
     * The rule every ordering operator (`>`, `<`, `>=`, `<=`) follows. They all compare two numbers the same way, so
     * they share one check and one wording, and can't drift into blaming different parts of the expression for the same
     * mistake.
     */
    private static function assertComparable(Expression $left, Expression $right): void
    {
        self::assertSameNumberType($left, $right, static fn(Expression $bad, Expression $good): string => sprintf(
            'Can\'t compare %s to %s',
            $bad->getType(),
            $good->getType(),
        ));
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
     * A call returns what its inline annotation says, and what the declaration says if it has no annotation. At least
     * one of the two has to be there, and where both are, the annotation has to fit the declaration. Those are the same
     * rules a variable's type follows; see {@see Parser\ExpressionParser::variable()}.
     *
     * Resolving the return type here rather than taking one is what keeps a Call's type and its signature from
     * contradicting each other: there is no way to hand this a return type that the declaration disagrees with.
     */
    private static function returnType(
        string $name,
        TypeAnnotation|null $annotation,
        Type|null $signature,
        Span $nameLocation,
    ): Type {
        if ($annotation === null) {
            return $signature?->returnType() ?? throw TypeError::create(
                sprintf('Function %s is not declared and has no inline type', $name),
                $nameLocation,
            );
        }
        if ($signature !== null && !$annotation->type->isSubtypeOf($signature->returnType())) {
            throw TypeError::create(
                sprintf(
                    'Inline return type %s of function %s does not match declared return type %s',
                    $annotation->type,
                    $name,
                    $signature->returnType(),
                ),
                $annotation->location,
            );
        }
        return $annotation->type;
    }

    /**
     * A receiver function takes the expression it's called on as its first argument: `substr` is declared as
     * func(string, [string, int, int]) and called as `foo:string.substr:string(0, 3)`, so `foo` has to be a string.
     */
    private static function checkReceiver(Expression $target, string $name, Type $signature): void
    {
        $receiverType = $signature->receiverType();
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
        $argumentTypes = $signature->argumentTypes();
        if (count($arguments) !== count($argumentTypes)) {
            // An argument too many is right there to point at. One that's missing isn't anywhere, so the call as a
            // whole is the closest we can get.
            $surplus = $arguments[count($argumentTypes)] ?? null;
            throw TypeError::create(
                sprintf('%s expects %d arguments, got %d', $name, count($argumentTypes), count($arguments)),
                $surplus?->location() ?? $location,
            );
        }
        foreach ($argumentTypes as $index => $argumentType) {
            $argument = $arguments[$index];
            if ($argument->isSubtypeOf($argumentType)) {
                continue;
            }
            throw TypeError::create(
                sprintf(
                    'Argument %d of %s must be of type %s, got %s',
                    $index + 1,
                    $name,
                    $argumentType,
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
}
