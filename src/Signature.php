<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use function array_map;
use function array_slice;
use function count;

/**
 * A function type read as what it's for: a return type, a receiver, and the arguments a call passes in parentheses.
 *
 * $typeVariables is not derived by this constructor: a signature built directly through it doesn't yet know whether
 * it's a complete top-level declaration or another {@see Type::func()}'s own parameter or return type -- only the
 * first of those owns a binder at all, since rank-1 polymorphism gives every variable reachable inside a signature
 * to whichever one encloses it, and there is nothing about the return type and parameters alone that says which one
 * this signature will turn out to be. {@see Type::asFunction()} is the way to get a signature read as a complete
 * top-level declaration, with its binder derived; that's also what {@see Type::__toString()} prints back.
 *
 * @api
 */
final class Signature
{
    /**
     * @param list<Type> $parameters The types the PHP callable receives, in order, receiver first -- `substr` is
     *     declared as `fn(string, int, int) -> string` and called as `foo:string.substr:string(0, 3)`, so its
     *     parameters are `[string, int, int]` and `foo` is checked against the first of them; see
     *     {@see self::receiverType()} and {@see self::argumentTypes()}.
     * @param list<string> $typeVariables The names this signature's own `fn<...>` binder declares, in the order
     *     first found walking $parameters then $returnType, without a duplicate -- empty unless something has
     *     already derived it, which nothing but {@see Type::asFunction()} does; see this class's own docblock for
     *     why that isn't this constructor.
     */
    public function __construct(
        public readonly Type $returnType,
        public readonly array $parameters = [],
        public readonly array $typeVariables = [],
    ) {
    }

    /**
     * The type a receiver function is called on: `substr` is declared as `fn(string, int, int) -> string` and called as
     * `foo:string.substr:string(0, 3)`, so its receiver type is string. Null if the function declares no parameters at
     * all, which is what makes it unusable as a receiver function.
     */
    public function receiverType(): Type|null
    {
        return $this->parameters[0] ?? null;
    }

    /**
     * The types of the arguments a call passes in parentheses, which are the parameters the receiver doesn't take up.
     *
     * @return list<Type>
     */
    public function argumentTypes(): array
    {
        return array_slice($this->parameters, 1);
    }

    /**
     * This signature with its type variables resolved against the receiver and argument types a call applies it to:
     * the signature the call is actually checked against, with no variables left in it.
     *
     * The parameters -- receiver first, then the arguments a call passes in parentheses -- are walked in order and a
     * variable keeps the first type that lands on it, so the receiver decides `T` for `fn(list<T>, T) -> bool` before
     * the argument is looked at. A variable no parameter reaches becomes `any`—nothing constrained it, so nothing
     * about the call should be rejected on its account, and an argument that isn't there is reported as the missing
     * argument it is.
     *
     * A lambda argument is harmless regardless of where its parameter falls in that order: a lambda's own parameters
     * are always typed `any`, since a lambda never knows its parameter types ahead of the call it's an argument to, and
     * {@see Type::bind()} doesn't let a parameter typed `any` decide a variable that sits in a function's own parameter
     * position, walked first or not.
     *
     * Nothing here is an error. Where a variable's binding and a later parameter disagree, the substituted signature
     * says what was expected and {@see Expr::call()}'s receiver and argument checks report it, pointing at the
     * expression that's wrong.
     *
     * A signature that names no variable at all is returned unchanged rather than substituted for nothing: this is
     * only ever called on a signature obtained from {@see Type::asFunction()}, so an empty $typeVariables here means
     * there is no variable left anywhere in $returnType or $parameters for {@see Type::bind()} to find, and
     * substituting would walk the whole signature only to rebuild it unchanged. Skipping it is purely that
     * optimization -- correct either way, not load-bearing for either.
     *
     * @param list<Type> $argumentTypes
     */
    public function instantiateForCall(Type $receiver, array $argumentTypes): self
    {
        if ($this->typeVariables === []) {
            return $this;
        }
        $arguments = [$receiver, ...$argumentTypes];
        $bindings = [];
        // Only the parameters an argument faces have anything to say. A call with the wrong number of arguments is
        // still instantiated, from the ones it does have, so that Expr::call() can report the count against a
        // signature that reads the way the rest of the call decided it should.
        foreach (array_slice($this->parameters, 0, count($arguments)) as $index => $parameter) {
            $bindings = $parameter->bind($arguments[$index], $bindings);
        }
        return new self(
            $this->returnType->substitute($bindings),
            array_map(static fn(Type $parameter): Type => $parameter->substitute($bindings), $this->parameters),
        );
    }
}
