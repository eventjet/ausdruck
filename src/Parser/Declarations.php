<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Parser;

use Eventjet\Ausdruck\BuiltinFunctions;
use Eventjet\Ausdruck\Signature;
use Eventjet\Ausdruck\Type;
use InvalidArgumentException;

use function array_key_exists;
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
        foreach ($variables as $name => $type) {
            self::checkVariableIsSelfContained($name, $type);
        }
        $fns = BuiltinFunctions::signatures();
        foreach ($functions as $name => $type) {
            if (array_key_exists($name, $fns)) {
                throw new InvalidArgumentException(sprintf('Can\'t override built-in function %s', $name));
            }
            $signature = Signature::quantified($type);
            if ($signature === null) {
                throw new InvalidArgumentException(sprintf('%s is declared as %s, which is not a function type', $name, $type));
            }
            $fns[$name] = $signature;
        }
        $this->functions = $fns;
    }

    /**
     * The one place a consumer-supplied variable's declared type is checked before it's trusted: unlike a function,
     * which {@see Signature::quantified()} promotes into a complete signature by declaring it, a variable's type is
     * never quantified -- there is no `fn<...>` binder here for a {@see Type::var()} to belong to. A bare one used
     * on its own, or one reached through a list, an `Option`, a struct field, or a {@see Type::func()} that nothing
     * has quantified, is rejected instead of silently becoming `any` wherever the variable was reached; see
     * {@see Type::hasFreeVariables()}.
     */
    private static function checkVariableIsSelfContained(string $name, Type $type): void
    {
        if (!$type->hasFreeVariables()) {
            return;
        }
        throw new InvalidArgumentException(sprintf(
            '%s is declared as %s, which reaches a type variable nothing captures -- a variable\'s declared type '
                . 'is never quantified the way a function\'s own declaration or a Type::alias() target is, so '
                . 'nothing here would ever bind it',
            $name,
            $type,
        ));
    }
}
