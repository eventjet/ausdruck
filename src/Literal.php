<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Eventjet\Ausdruck\Parser\Span;
use Override;

use function array_is_list;
use function array_map;
use function implode;
use function is_array;
use function is_float;
use function is_int;
use function is_null;
use function is_string;
use function sprintf;
use function var_export;

/**
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class Literal extends AbstractLiteral
{
    use LocationTrait;

    /**
     * @param string | int | float | bool | null | array<array-key, mixed> $value
     */
    public function __construct(private readonly mixed $value, Span $location)
    {
        $this->location = $location;
    }

    /**
     * The source spelling of a value, where it has one. `none` doesn't: {@see Type::fromValue()} reads PHP's null as
     * the None type, and it prints here as `null` because that is what it is in PHP, but the language has no null
     * literal to read it back with. A none-valued expression therefore prints to something the parser rejects — the
     * one value this produces that doesn't round-trip, and the reason the test cases that evaluate to none assert
     * through `isSome` instead of naming the value.
     */
    private static function dumpValue(mixed $value): string
    {
        if (is_string($value)) {
            return sprintf('"%s"', $value);
        }
        if (is_null($value)) {
            return 'null';
        }
        if (!is_array($value)) {
            return var_export($value, true);
        }
        if (array_is_list($value)) {
            return sprintf('[%s]', implode(', ', array_map(self::dumpValue(...), $value)));
        }
        $pairs = [];
        /** @var string | int | bool | null | array<array-key, mixed> $item */
        foreach ($value as $key => $item) {
            $pairs[] = sprintf('%s: %s', self::dumpValue($key), self::dumpValue($item));
        }
        return sprintf('{%s}', implode(', ', $pairs));
    }

    public function __toString(): string
    {
        return self::dumpValue($this->value);
    }

    /**
     * A number prints as bare digits, so an immediately following `.` would be read as a decimal point rather than a
     * field or method access. {@see Precedence::parenthesizeTarget()} wraps such a receiver in parentheses because of
     * it.
     */
    public function isNumber(): bool
    {
        return is_int($this->value) || is_float($this->value);
    }

    #[Override]
    public function evaluate(Scope $scope): mixed
    {
        return $this->value;
    }

    #[Override]
    public function equals(Expression $other): bool
    {
        return $other instanceof self
            && $this->value === $other->value;
    }

    #[Override]
    public function getType(): Type
    {
        return Type::fromValue($this->value);
    }

    #[Override]
    public function value(): mixed
    {
        return $this->value;
    }
}
