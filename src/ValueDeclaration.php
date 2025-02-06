<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

final readonly class ValueDeclaration
{
    public function __construct(public string $name, public Type $type)
    {
    }
}
