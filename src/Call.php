<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Eventjet\Ausdruck\Parser\Span;
use Throwable;

use function array_map;
use function array_unshift;
use function count;
use function implode;
use function sprintf;

/**
 * @template T
 * @extends Expression<T>
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class Call extends Expression
{
    use LocationTrait;

    /**
     * @param Expression<mixed> $target
     * @param list<Expression<mixed>> $arguments
     * @param Type<T> $type
     */
    public function __construct(
        public readonly Expression $target,
        public readonly string $name,
        public readonly Type $type,
        public readonly array $arguments,
        Span $location,
        public readonly CallType $callType = CallType::Method,
    ) {
        $this->location = $location;
    }

    /**
     * @param list<Expression<mixed>> $a
     * @param list<Expression<mixed>> $b
     */
    private static function compareArguments(array $a, array $b): bool
    {
        if (count($a) !== count($b)) {
            return false;
        }
        foreach ($a as $i => $arg) {
            if ($arg->equals($b[$i])) {
                continue;
            }
            return false;
        }
        return true;
    }

    public function __toString(): string
    {
        return match ($this->callType) {
            CallType::Infix => sprintf('%s %s %s', $this->target, $this->name, $this->arguments[0]),
            CallType::Prefix => sprintf('%s%s', $this->name, $this->target),
            /** @psalm-suppress ImplicitToStringCast */
            CallType::Method => sprintf('%s.%s:%s(%s)', $this->target, $this->name, $this->type, implode(', ', $this->arguments)),
        };
    }

    public function evaluate(Scope $scope): mixed
    {
        $func = $scope->func($this->name);
        if ($func === null) {
            throw new EvaluationError(sprintf('Unknown function "%s"', $this->name));
        }
        $args = array_map(static fn(Expression $arg): mixed => $arg->evaluate($scope), $this->arguments);
        array_unshift($args, $this->target->evaluate($scope));
        try {
            return $this->type->assert($func(...$args));
        } catch (Throwable $error) {
            throw new EvaluationError($error->getMessage(), previous: $error);
        }
    }

    public function equals(Expression $other): bool
    {
        /** @psalm-suppress RedundantConditionGivenDocblockType False positive */
        return $other instanceof self
            && $this->target->equals($other->target)
            && $this->name === $other->name
            && $this->type->equals($other->type)
            && self::compareArguments($this->arguments, $other->arguments);
    }

    public function getType(): Type
    {
        return $this->type;
    }
}
