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
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class Call extends Expression
{
    use LocationTrait;

    /**
     * @param list<Expression> $arguments
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

    public static function infix(string $name, Expression $left, Expression $right, Type $type): self
    {
        return new self($left, $name, $type, [$right], $left->location()->to($right->location()), CallType::Infix);
    }

    public static function prefix(string $name, Expression $argument, Type $type, Span $location): self
    {
        return new self($argument, $name, $type, [], $location, CallType::Prefix);
    }

    /**
     * @param list<Expression> $a
     * @param list<Expression> $b
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
