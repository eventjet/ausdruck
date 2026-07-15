<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Parser;

use Eventjet\Ausdruck\BuiltinFunctions;
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
        $fns = [];
        foreach (BuiltinFunctions::all() as $name => $fn) {
            // filter, head, map, tail and unwrap can't be declared until we have generics; they carry a null type and
            // rely on the parser's inline-return-type escape hatch instead.
            if ($fn['type'] === null) {
                continue;
            }
            $fns[$name] = $fn['type'];
        }
        foreach ($functions as $name => $type) {
            if (array_key_exists($name, $fns)) {
                throw new InvalidArgumentException(sprintf('Can\'t override built-in function %s', $name));
            }
            $fns[$name] = $type;
        }
        $this->functions = $fns;
    }
}
