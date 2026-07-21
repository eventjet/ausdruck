<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use function array_slice;
use function count;

/**
 * A function type read as what it's for: a return type, a receiver, and the arguments a call passes in parentheses.
 * {@see Type::func()} keeps all of that in one list of args, args[0] the return type and the rest the parameters,
 * receiver first -- and this is the only place that knows it. Get one from {@see Type::asFunction()}, which is also
 * the only thing that constructs one: it hands this the type with its alias, if any, already seen through, so
 * everything here can read $type's args directly instead of unwrapping it itself.
 *
 * @api
 */
final class Signature
{
    private function __construct(private readonly Type $type)
    {
    }

    /**
     * $type as a function's signature, or null if it isn't one: only {@see Type::func()} builds a type this accepts.
     * $type must already be canonical -- see {@see Type::asFunction()}, the only caller.
     *
     * @internal
     * @psalm-internal Eventjet\Ausdruck
     */
    public static function tryFrom(Type $type): self|null
    {
        return $type->name === 'fn' ? new self($type) : null;
    }

    /**
     * Returns the return type of a function type.
     */
    public function returnType(): Type
    {
        return $this->type->args[0];
    }

    /**
     * The type a receiver function is called on: `substr` is declared as `fn(string, int, int) -> string` and called as
     * `foo:string.substr:string(0, 3)`, so its receiver type is string. Null if the function declares no parameters at
     * all, which is what makes it unusable as a receiver function.
     */
    public function receiverType(): Type|null
    {
        return $this->parameterTypes()[0] ?? null;
    }

    /**
     * The types of the arguments a call passes in parentheses, which are the parameters the receiver doesn't take up.
     *
     * @return list<Type>
     */
    public function argumentTypes(): array
    {
        return array_slice($this->parameterTypes(), 1);
    }

    /**
     * Every parameter of this function, receiver included, in the order the PHP callable receives them. Two function
     * types are compared parameter by parameter, so this is the list that matters for subtyping; the split into a
     * receiver and the arguments only matters at a call site.
     *
     * @internal
     * @psalm-internal Eventjet\Ausdruck
     *
     * @return list<Type>
     */
    public function parameterTypes(): array
    {
        return array_slice($this->type->args, 1);
    }

    /**
     * This signature with its type variables resolved against the receiver and argument types a call applies it to:
     * the signature the call is actually checked against, with no variables left in it.
     *
     * The parameters -- receiver first, then the arguments a call passes in parentheses, the one layout convention
     * this spells out so that nowhere else has to -- are walked in order and a variable keeps the first type that
     * lands on it, so the receiver decides `T` for `fn(list<T>, T) -> bool` before the argument is looked at. A
     * variable no parameter reaches becomes `any`—nothing constrained it, so nothing about the call should be rejected
     * on its account, and an argument that isn't there is reported as the missing argument it is.
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
     * A signature that names no variable at all -- {@see Type::hasTypeVariables()} -- is returned unchanged rather
     * than substituted for nothing: there is nothing {@see Type::substitute()} could bind on such a signature, and
     * skipping it outright is what guarantees it can't damage one, an alias's own arguments included, rather than
     * relying on {@see Type::substitute()} happening to be harmless when there are no bindings.
     *
     * @param list<Type> $argumentTypes
     */
    public function instantiateForCall(Type $receiver, array $argumentTypes): self
    {
        if (!$this->type->hasTypeVariables()) {
            return $this;
        }
        $arguments = [$receiver, ...$argumentTypes];
        $bindings = [];
        // Only the parameters an argument faces have anything to say. A call with the wrong number of arguments is
        // still instantiated, from the ones it does have, so that Expr::call() can report the count against a
        // signature that reads the way the rest of the call decided it should.
        foreach (array_slice($this->parameterTypes(), 0, count($arguments)) as $index => $parameter) {
            $bindings = $parameter->bind($arguments[$index], $bindings);
        }
        return new self($this->type->substitute($bindings));
    }
}
