<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Parser;

use Eventjet\Ausdruck\Type;
use InvalidArgumentException;

use function array_key_exists;
use function sprintf;

final class Declarations
{
    /** @var array<string, Type> */
    public readonly array $functions;

    /**
     * @param array<string, Type> $variables
     * @param array<string, Type> $functions
     */
    public function __construct(
        public readonly Types $types = new Types(),
        public readonly array $variables = [],
        array $functions = [],
    ) {
        $fns = [
            'contains' => Type::func(Type::bool(), [Type::listOf(Type::any()), Type::any()]),
            'count' => Type::func(Type::int(), [Type::listOf(Type::any())]),
            // Can't declare filter until we have generics
            // 'filter' => Type::func(Type::listOf(Type::any()), [Type::listOf(Type::any()), Type::func(Type::bool(), [Type::any()])]),
            'isSome' => Type::func(Type::bool(), [Type::option(Type::any())]),
            // Can't declare map until we have generics
            // 'map' => Type::func(Type::listOf(Type::any()), [Type::listOf(Type::any()), Type::func(Type::any(), [Type::any()])]),
            'some' => Type::func(Type::bool(), [Type::listOf(Type::any()), Type::func(Type::bool(), [Type::any()])]),
            'substr' => Type::func(Type::string(), [Type::string(), Type::int(), Type::int()]),
            'take' => Type::func(Type::listOf(Type::any()), [Type::listOf(Type::any()), Type::int()]),
            'unique' => Type::func(Type::listOf(Type::any()), [Type::listOf(Type::any())]),
            // Can't declare unwrap until we have generics
            // 'unwrap' => Type::func(Type::some(Type::any()), [Type::option(Type::any())]),
        ];
        foreach ($functions as $name => $type) {
            if (array_key_exists($name, $fns)) {
                throw new InvalidArgumentException(sprintf('Can\'t override built-in function %s', $name));
            }
            $fns[$name] = $type;
        }
        $this->functions = $fns;
    }
}
