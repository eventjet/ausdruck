<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

/**
 * The base for literal nodes — expressions whose value is known without a scope. The {@see self::value()} extension
 * point exists only so a literal can materialize itself and its nested literals ({@see ListLiteral}, {@see StructLiteral})
 * without going through {@see Expression::evaluate()}; the three subclasses that need it all live in this package, so
 * nothing outside it has reason to add a fourth.
 *
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
abstract class AbstractLiteral extends Expression
{
    abstract public function value(): mixed;
}
