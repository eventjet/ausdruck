<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Test\Unit\Parser;

use Eventjet\Ausdruck\Parser\SyntaxError;
use PHPUnit\Framework\TestCase;

final class SyntaxErrorTest extends TestCase
{
    public function testTheCodeDefaultsToZero(): void
    {
        $error = new SyntaxError();

        self::assertSame(0, $error->getCode());
    }
}
