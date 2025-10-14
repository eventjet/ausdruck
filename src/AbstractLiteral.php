<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

abstract class AbstractLiteral extends Expression
{
    abstract public function value(): mixed;
}
