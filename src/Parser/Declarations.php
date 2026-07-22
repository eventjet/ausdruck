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
        $fns = BuiltinFunctions::signatures();
        foreach ($functions as $name => $type) {
            if (array_key_exists($name, $fns)) {
                throw new InvalidArgumentException(sprintf('Can\'t override built-in function %s', $name));
            }
            $signature = $type->asFunction();
            if ($signature === null) {
                throw new InvalidArgumentException(sprintf('%s is declared as %s, which is not a function type', $name, $type));
            }
            self::checkBinderCoversFreeVariables($name, $type, $signature);
            $fns[$name] = $signature;
        }
        $this->functions = $fns;
    }

    /**
     * This is the one place a consumer-supplied {@see Type} is promoted to a declaration, so it's the one place that
     * has to notice a signature built through the wrong door: {@see Type::nestedFunc()} defers every variable it
     * reaches to whichever signature encloses it, correct for a function type nested inside another one's own
     * parameters or return type, but wrong here, where nothing encloses it -- the signature reads back with an empty
     * binder even though {@see Signature::freeVariables()} finds names in it, and every one of them silently becomes
     * `any` at every call site instead of being decided by the call. {@see Type::func()} and
     * {@see Type::genericFunc()}, the two doors meant for a top-level declaration, can't produce that: each already
     * derives or validates its own binder against exactly the variables it reaches, both ways, so the only way a
     * declared signature ever disagrees with its own free variables is by having been built through the wrong door
     * in the first place -- checked here as that one fact, not re-derived as the two-directional set comparison
     * {@see Type::genericFunc()} itself already made true by construction.
     */
    private static function checkBinderCoversFreeVariables(string $name, Type $type, Signature $signature): void
    {
        if (
            !$signature->hasOwnBinder()
            && Signature::freeVariables($signature->returnType, $signature->parameters) !== []
        ) {
            throw new InvalidArgumentException(sprintf(
                '%s is declared as %s, built through Type::nestedFunc(), which leaves it without a binder of its '
                    . 'own -- a declaration needs Type::func() or Type::genericFunc() instead',
                $name,
                $type,
            ));
        }
    }
}
