<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use function array_slice;
use function count;

/**
 * A function type read as what it's for: a return type, a receiver, and the arguments a call passes in parentheses.
 * {@see Type::func()} keeps all of that in one list of args, args[0] the return type and the rest the parameters,
 * receiver first -- and this is the only place that knows it. Get one from {@see Type::asFunction()}.
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
     */
    public static function tryFrom(Type $type): self|null
    {
        return self::canonicalize($type)->name === 'Func' ? new self($type) : null;
    }

    private static function canonicalize(Type $type): Type
    {
        return $type->aliasFor ?? $type;
    }

    /**
     * Returns the return type of a function type.
     */
    public function returnType(): Type
    {
        return self::canonicalize($this->type)->args[0];
    }

    /**
     * The type a receiver function is called on: `substr` is declared as func(string, [string, int, int]) and called as
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
     * This signature with its type variables resolved against the receiver and argument types a call applies it to:
     * the signature the call is actually checked against, with no variables left in it.
     *
     * The parameters are walked in order and a variable keeps the first type that lands on it, so the receiver decides
     * `T` for `func(bool, [list<T>, T])` before the argument is looked at. That order is what makes a lambda argument
     * harmless: a lambda's parameters are typed `any` and would decide nothing useful, but by then the receiver has
     * already decided. A variable no parameter reaches becomes `any`—nothing constrained it, so nothing about the call
     * should be rejected on its account, and an argument that isn't there is reported as the missing argument it is.
     *
     * Nothing here is an error. Where a variable's binding and a later parameter disagree, the substituted signature
     * says what was expected and {@see Expr::call()}'s receiver and argument checks report it, pointing at the
     * expression that's wrong.
     *
     * @param list<Type> $arguments The types the call applies, receiver first, in the order {@see self::parameterTypes()}
     *     lists the parameters.
     */
    public function instantiate(array $arguments): self
    {
        $bindings = [];
        // Only the parameters an argument faces have anything to say. A call with the wrong number of arguments is
        // still instantiated, from the ones it does have, so that Expr::call() can report the count against a
        // signature that reads the way the rest of the call decided it should.
        foreach (array_slice($this->parameterTypes(), 0, count($arguments)) as $index => $parameter) {
            $bindings = $parameter->bind($arguments[$index], $bindings);
        }
        return new self($this->type->substitute($bindings));
    }

    /**
     * {@see self::instantiate()}, spelling out the one layout convention it depends on -- receiver first, then the
     * arguments a call passes in parentheses -- so that nowhere else has to.
     *
     * @param list<Type> $argumentTypes
     */
    public function instantiateForCall(Type $receiver, array $argumentTypes): self
    {
        return $this->instantiate([$receiver, ...$argumentTypes]);
    }

    /**
     * Every parameter of this function, receiver included, in the order the PHP callable receives them. Two function
     * types are compared parameter by parameter, so this is the list that matters for subtyping; the split into a
     * receiver and the arguments only matters at a call site.
     *
     * @return list<Type>
     */
    private function parameterTypes(): array
    {
        return array_slice(self::canonicalize($this->type)->args, 1);
    }
}
