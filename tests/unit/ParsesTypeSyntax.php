<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Test\Unit;

use Eventjet\Ausdruck\Parser\TypeNode;
use Eventjet\Ausdruck\Parser\TypeParser;

/**
 * The one door the test suite has to {@see TypeParser::parseDeclarations()}: it is `@psalm-internal
 * Eventjet\Ausdruck\Parser`, so a test that calls it directly -- reaching in from outside that namespace -- is
 * otherwise an InternalClass/InternalMethod violation Psalm has to be told to allow, repeated at every call site.
 * Suppressing it once here, in the one place it is wrapped, is what {@see E2eCase} reaches for.
 */
trait ParsesTypeSyntax
{
    /**
     * @return array<string, TypeNode>
     *
     * @psalm-suppress InternalMethod
     * @psalm-suppress InternalClass
     */
    private static function parseTypeDeclarations(string $declarations): array
    {
        return TypeParser::parseDeclarations($declarations);
    }
}
