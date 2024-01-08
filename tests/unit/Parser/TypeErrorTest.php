<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Test\Unit\Parser;

use Eventjet\Ausdruck\Parser\TypeError;
use PHPUnit\Framework\TestCase;

final class TypeErrorTest extends TestCase
{
    public function testTheCodeDefaultsToZero(): void
    {
        $error = new TypeError();

        self::assertSame(0, $error->getCode());
    }
}
