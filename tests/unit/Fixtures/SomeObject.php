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
     * @psalm-suppress UnusedProperty
     * @phpstan-ignore-next-line
     */
    private string $private = 'private';
}
