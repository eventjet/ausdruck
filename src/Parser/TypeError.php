<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Parser;

use RuntimeException;

final class TypeError extends RuntimeException
{
    public function __construct(string $message = '', public readonly Span|null $location = null)
    {
        parent::__construct($message);
    }
}
