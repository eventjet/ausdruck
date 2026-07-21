<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Parser;

use Eventjet\Ausdruck\BuiltinFunctions;
use Eventjet\Ausdruck\Signature;
use Eventjet\Ausdruck\Type;
use InvalidArgumentException;

use function array_key_exists;
use function assert;
use function sprintf;

final class Declarations
{
    /**
     * A {@see Signature}, not the {@see Type} it was declared with: rejecting a non-function declaration here, rather
     * than downgrading it to "undeclared" wherever it's read, is only worth doing if what's read back can't need that
     * same check again. {@see ExpressionParser::call()} and {@see \Eventjet\Ausdruck\Expr::call()} read this straight
     * into the receiver and argument checks.
     *
     * @var array<string, Signature>
     */
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
        foreach (BuiltinFunctions::types() as $name => $type) {
            $signature = $type->asFunction();
            // Every built-in is declared with Type::func(), so this never actually fails; the assert is here for the
            // type checker, not because this can go wrong at runtime.
            assert($signature !== null);
            $fns[$name] = $signature;
        }
        foreach ($functions as $name => $type) {
            if (array_key_exists($name, $fns)) {
                throw new InvalidArgumentException(sprintf('Can\'t override built-in function %s', $name));
            }
            $signature = $type->asFunction();
            if ($signature === null) {
                throw new InvalidArgumentException(sprintf('%s is declared as %s, which is not a function type', $name, $type));
            }
            $fns[$name] = $signature;
        }
        $this->functions = $fns;
    }
}
