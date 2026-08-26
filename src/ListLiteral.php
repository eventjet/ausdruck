<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Eventjet\Ausdruck\Formatter\Doc;
use Eventjet\Ausdruck\Formatter\HasDoc;
use Eventjet\Ausdruck\Formatter\PrintsItsDoc;
use Eventjet\Ausdruck\Parser\Span;
use Override;
use RuntimeException;

use function array_map;
use function sprintf;

/**
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class ListLiteral extends AbstractLiteral implements HasDoc
{
    use PrintsItsDoc;

    /**
     * @param list<Expression> $elements
     */
    public function __construct(public readonly array $elements, public readonly Span $location)
    {
    }

    #[Override]
    public function doc(): Doc
    {
        return Doc::commaSeparated('[', array_map(Doc::of(...), $this->elements), ']');
    }

    #[Override]
    public function location(): Span
    {
        return $this->location;
    }

    /**
     * @return list<mixed>
     */
    #[Override]
    public function evaluate(Scope $scope): array
    {
        return array_map(
            static fn(Expression $element): mixed => $element->evaluate($scope),
            $this->elements,
        );
    }

    #[Override]
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

    #[Override]
    public function getType(): Type
    {
        $elementType = null;
        foreach ($this->elements as $element) {
            $type = $element->getType();
            if ($elementType === null) {
                $elementType = $type;
                continue;
            }
            if ($elementType->equals($type)) {
                continue;
            }
            $elementType = Type::any();
        }
        return Type::listOf($elementType ?? Type::any());
    }

    #[Override]
    public function value(): mixed
    {
        $out = [];
        foreach ($this->elements as $index => $element) {
            if (!$element instanceof AbstractLiteral) {
                throw new RuntimeException(sprintf('Element at index %d is not a literal', $index));
            }
            /** @psalm-suppress MixedAssignment */
            $out[] = $element->value();
        }
        return $out;
    }
}
