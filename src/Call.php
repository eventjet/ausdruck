<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Eventjet\Ausdruck\Formatter\Doc;
use Eventjet\Ausdruck\Formatter\HasDoc;
use Eventjet\Ausdruck\Formatter\PrintsItsDoc;
use Eventjet\Ausdruck\Parser\Span;
use Override;
use Throwable;

use function array_map;
use function count;
use function sprintf;

/**
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class Call extends Expression implements HasDoc
{
    use LocationTrait;
    use PrintsItsDoc;

    /**
     * @param list<Expression> $arguments
     */
    public function __construct(
        public readonly Expression $target,
        public readonly string $name,
        public readonly Type $type,
        public readonly array $arguments,
        Span $location,
    ) {
        $this->location = $location;
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

    #[Override]
    public function doc(): Doc
    {
        return PostfixChain::doc($this);
    }

    #[Override]
    public function evaluate(Scope $scope): mixed
    {
        $func = $scope->func($this->name);
        if ($func === null) {
            throw new EvaluationError(sprintf('Unknown function "%s"', $this->name));
        }
        if ($func instanceof TypeDependentFunction) {
            $func = $func->specialize(
                $this->target->getType(),
                array_map(static fn(Expression $argument): Type => $argument->getType(), $this->arguments),
                $this->type,
            );
        }
        $arguments = array_map(static fn(Expression $arg): mixed => $arg->evaluate($scope), $this->arguments);
        $args = [$this->target->evaluate($scope), ...$arguments];
        try {
            return $this->type->assert($func(...$args));
        } catch (Throwable $error) {
            throw new EvaluationError($error->getMessage(), previous: $error);
        }
    }

    #[Override]
    public function equals(Expression $other): bool
    {
        return $other instanceof self
            && $this->target->equals($other->target)
            && $this->name === $other->name
            && $this->type->equals($other->type)
            && self::compareArguments($this->arguments, $other->arguments);
    }

    #[Override]
    public function getType(): Type
    {
        return $this->type;
    }
}
