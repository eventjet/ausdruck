<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Test\Unit\Fixtures;

final class SomeObject
{
    /**
     * @psalm-suppress PossiblyUnusedProperty
     */
    protected string $protected = 'protected';
    /**
     * @phpstan-ignore-next-line
     * @psalm-suppress UnusedProperty
     */
    private string $private = 'private';
}
