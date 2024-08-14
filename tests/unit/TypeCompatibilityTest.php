<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Test\Unit;

use Eventjet\Ausdruck\Parser\SyntaxError;
use Eventjet\Ausdruck\Parser\TypeError;
use Eventjet\Ausdruck\Parser\TypeParser;
use Eventjet\Ausdruck\Parser\Types;
use Eventjet\Ausdruck\Type;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function sprintf;

final class TypeCompatibilityTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function isSubtypeCases(): iterable
    {
        $cases = [
            // Every type is a subtype of itself
            ['any', 'any'],
            ['bool', 'bool'],
            ['float', 'float'],
            ['int', 'int'],
            ['string', 'string'],
            ['fn() -> string', 'fn() -> string'],
            ['fn(int) -> string', 'fn(int) -> string'],
            ['fn(int, string) -> bool', 'fn(int, string) -> bool'],
            ['Option<string>', 'Option<string>'],
            ['Some<string>', 'Some<string>'],

            // Every type is a subtype of any
            ['bool', 'any'],
            ['float', 'any'],
            ['int', 'any'],
            ['string', 'any'],
            ['fn() -> string', 'any'],
            ['fn(int) -> string', 'any'],
            ['fn(int, string) -> bool', 'any'],
            ['Option<int>', 'any'],
            ['Some<int>', 'any'],

            // Functions
            ['fn() -> string', 'fn() -> any'],
            ['fn(any) -> string', 'fn(int) -> string'],
            ['fn(int) -> int', 'fn(int, string) -> int'],

            // Option
            ['Option<string>', 'Option<any>'],
            ['Some<string>', 'Option<string>'],

            // Lists
            ['list<any>', 'list<any>'],
            ['list<string>', 'list<string>'],
            ['list<string>', 'list<any>'],
        ];
        foreach ($cases as $case) {
            yield sprintf('%s is a subtype of %s', ...$case) => $case;
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function isNotSubtypeCases(): iterable
    {
        $cases = [
            // Any is not a subtype of any other type (except itself)
            ['any', 'bool'],
            ['any', 'float'],
            ['any', 'int'],
            ['any', 'string'],
            ['any', 'fn() -> string'],
            ['any', 'fn(int) -> string'],
            ['any', 'fn(int, string) -> bool'],
            ['any', 'Option<bool>'],
            ['any', 'Some<bool>'],

            // Functions
            ['fn() -> any', 'fn() -> int'],
            ['fn() -> string', 'fn() -> int'],
            ['fn(string) -> int', 'fn(any) -> int'],
            ['fn(string) -> int', 'fn(int) -> int'],
            ['fn(int, string) -> int', 'fn(int) -> int'],

            // Misc
            ['fn() -> int', 'int'],
            ['int', 'fn() -> int'],

            // Option
            ['Option<int>', 'Option<string>'],
            ['Option<any>', 'Option<string>'],
            ['Option<string>', 'Some<string>'],
            ['Some<string>', 'string'],

            // Lists
            ['list<string>', 'list<int>'],
            ['list<any>', 'list<string>'],
        ];
        foreach ($cases as $case) {
            yield sprintf('%s is not a subtype of %s', ...$case) => $case;
        }
    }

    private static function fromString(string $str, Types|null $types = null): Type
    {
        /**
         * @psalm-suppress InternalClass
         * @psalm-suppress InternalMethod
         */
        $node = TypeParser::parseString($str);
        if ($node instanceof SyntaxError) {
            throw $node;
        }
        $type = ($types ?? new Types())->resolve($node);
        if ($type instanceof TypeError) {
            throw $type;
        }
        return $type;
    }

    /**
     * @dataProvider isSubtypeCases
     */
    public function testIsSubtype(string $subtype, string $supertype): void
    {
        $super = self::fromString($supertype);
        $sub = self::fromString($subtype);

        self::assertTrue($sub->isSubtypeOf($super));
    }

    /**
     * @dataProvider isNotSubtypeCases
     */
    public function testIsNotSubtype(string $subtype, string $supertype): void
    {
        $super = self::fromString($supertype);
        $sub = self::fromString($subtype);

        self::assertFalse($sub->isSubtypeOf($super));
    }
}
