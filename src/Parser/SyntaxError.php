<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Parser;

use RuntimeException;
use Throwable;

final class SyntaxError extends RuntimeException
{
    public function __construct(
        string $message = '',
        int $code = 0,
        Throwable|null $previous = null,
        public readonly Span|null $location = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public static function create(string $message, Span $location): self
    {
        return new self($message, location: $location);
    }
}
