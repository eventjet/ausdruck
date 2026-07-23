<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Test\Unit;

use Eventjet\Ausdruck\Parser\SyntaxError;
use Eventjet\Ausdruck\Parser\TypeNode;
use Eventjet\Ausdruck\Parser\TypeParser;

/**
 * The one door the test suite has to {@see TypeParser}: it is `@psalm-internal Eventjet\Ausdruck\Parser`, so every
 * test that calls it directly -- reaching in from outside that namespace -- is otherwise an InternalClass/InternalMethod
 * violation Psalm has to be told to allow, repeated at every call site that reaches for one of its two public methods.
 * Suppressing it once here, in the one place both are wrapped, is what {@see E2eCase::parseDeclarations()} already
 * does for {@see TypeParser::parseDeclarations()} alone; this does the same for both methods, shared across every
 * test that needs either.
 */
trait ParsesTypeSyntax
{
    /**
     * @psalm-suppress InternalMethod
     * @psalm-suppress InternalClass
     */
    private static function parseTypeString(string $type): TypeNode|SyntaxError
    {
        return TypeParser::parseString($type);
    }

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
