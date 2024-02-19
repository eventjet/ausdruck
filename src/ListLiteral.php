<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Eventjet\Ausdruck\Parser\Span;

use function array_map;
use function implode;

/**
 * @template T
 * @extends Expression<list<T>>
 */
final class ListLiteral extends Expression
{
    /**
     * @param list<Expression<T>> $elements
     */
    public function __construct(public readonly array $elements, public readonly Span $location)
    {
    }

    public function __toString()
    {
        return '[' . implode(', ', $this->elements) . ']';
    }

    public function location(): Span
    {
        return $this->location;
    }

    /**
     * @return list<T>
     */
    public function evaluate(Scope $scope): array
    {
        return array_map(
            static fn(Expression $element): mixed => $element->evaluate($scope),
            $this->elements,
        );
    }

    public function equals(Expression $other): bool
    {
        if (!$other instanceof self) {
            return false;
        }
        foreach ($this->elements as $i => $element) {
            if ($element->equals($other->elements[$i])) {
                continue;
            }
            return false;
        }
        return true;
    }

    /**
     * @return Type<list<T>>
     */
    public function getType(): Type
    {
        $elementType = null;
        foreach ($this->elements as $element) {
            $type = $element->getType();
            if ($elementType === null) {
                $elementType = $type;
                continue;
            }
            /** @psalm-suppress RedundantCondition */
            if ($elementType->equals($type)) {
                continue;
            }
            $elementType = Type::any();
        }
        return Type::listOf($elementType ?? Type::any());
    }
}
