<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Test\Unit;

use Eventjet\Ausdruck\Parser\SyntaxError;
use Eventjet\Ausdruck\Parser\TypeError;

/**
 * The error an {@see E2eCase} expects its source to be rejected with.
 *
 * A case names the class by choosing the section it writes the message in, so the two always travel together: there is
 * no case that expects a message without a class, or a class without a message.
 */
final readonly class E2eError
{
    /**
     * @param class-string<SyntaxError | TypeError> $class
     */
    public function __construct(public string $class, public string $message)
    {
    }
}
