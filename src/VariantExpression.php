<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Eventjet\Ausdruck\Parser\Span;
use Eventjet\Ausdruck\Parser\TypeError;
use InvalidArgumentException;
use Override;
use RuntimeException;

use function array_map;
use function count;
use function implode;

/** @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class VariantExpression extends AbstractLiteral
{
    use LocationTrait;

    private readonly Type $type;

    /** @param list<Expression> $fields */
    public function __construct(private readonly EnumDefinition $definition, private readonly string $variant, private readonly array $fields, Span $location)
    {
        $this->location = $location;
        try {
            $this->type = $definition->infer($variant, array_map(static fn(Expression $e): Type => $e->getType(), $fields));
        } catch (InvalidArgumentException $e) {
            throw TypeError::create($e->getMessage(), $location);
        }
    }

    public function __toString(): string
    {
        return $this->variant . ($this->fields === [] ? '' : '(' . implode(', ', $this->fields) . ')');
    }

    #[Override]
    public function evaluate(Scope $scope): EnumValue
    {
        return new EnumValue($this->type, $this->variant, array_map(static fn(Expression $e): mixed => $e->evaluate($scope), $this->fields));
    }

    #[Override]
    public function value(): EnumValue
    {
        return new EnumValue($this->type, $this->variant, array_map(
            static fn(Expression $e): mixed => $e instanceof AbstractLiteral ? $e->value() : throw new RuntimeException('Variant field is not a literal'),
            $this->fields,
        ));
    }

    #[Override]
    public function getType(): Type
    {
        return $this->type;
    }

    #[Override]
    public function equals(Expression $other): bool
    {
        if (!$other instanceof self || $this->definition !== $other->definition || $this->variant !== $other->variant || count($this->fields) !== count($other->fields)) {
            return false;
        }
        foreach ($this->fields as $index => $field) {
            if (!$field->equals($other->fields[$index])) {
                return false;
            }
        }
        return true;
    }
}
