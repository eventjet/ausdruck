<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Parser;

use RuntimeException;

final class SyntaxError extends RuntimeException
{
    public function __construct(string $message, public readonly Span $location)
    {
        parent::__construct($message);
    }
}
