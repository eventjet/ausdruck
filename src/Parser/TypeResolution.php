<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Parser;

use Eventjet\Ausdruck\Type;
use Eventjet\Ausdruck\TypeConstructor;
use LogicException;

use function array_key_exists;
use function array_key_last;
use function assert;
use function count;
use function sprintf;

/**
 * One resolution of a {@see TypeNode} against a scope: the aliases {@see Types} was built with, and the type
 * variables the `fn<...>` binder enclosing the node being resolved has introduced, if any. Both are names that
 * resolve to a type rather than being one, which is why they're carried together instead of the aliases living on
 * {@see Types} and the variables being threaded through as a parameter—a binder can only tell whether the name it
 * wants to introduce is free to introduce by asking both at once; see {@see self::checkTypeVariable()}.
 *
 * A variable is quantified once, at the top of the signature it belongs to—see {@see Type::var()}—so a function type
 * nested inside another one's parameters or return type can never carry a binder of its own: {@see
 * self::resolveFunction()} rejects one outright rather than letting an inner `fn<...>` shadow or extend the outer
 * scope. $nestedInFunction is how it knows whether it's already inside a function type's scope; $typeVariables itself
 * only ever grows once, when the one binder a signature is allowed to have is resolved.
 *
 * @internal
 * @psalm-internal Eventjet\Ausdruck\Parser
 */
final class TypeResolution
{
    /**
     * @param array<string, Type> $aliases
     * @param array<string, true> $typeVariables
     */
    public function __construct(
        private readonly array $aliases,
        private readonly array $typeVariables = [],
        private readonly bool $nestedInFunction = false,
    ) {
    }

    /**
     * The one place a type argument count is checked. {@see TypeConstructor::typeArgumentCount()} says what each
     * constructor takes, and an alias takes none, so every wrong count is worded here rather than restated per
     * constructor. A surplus blames the arguments past the count that was wanted—the ones before it are what was asked
     * for—while too few blames all of them, because none of them is individually the mistake.
     *
     * @param int<0, max> $expected
     */
    private static function checkArity(TypeNode $node, int $expected): TypeError|null
    {
        $args = $node->args;
        $given = count($args);
        if ($given === $expected) {
            return null;
        }
        if ($args === []) {
            assert($expected > 0);
            return TypeError::create(
                sprintf('The %s type requires %s, none given', $node->name, self::spellArguments($expected)),
                $node->location,
            );
        }
        $last = $args[array_key_last($args)];
        if ($expected === 0) {
            return TypeError::create(
                sprintf('Invalid type "%s": %s does not accept arguments', $node, $node->name),
                $args[0]->location->to($last->location),
            );
        }
        return TypeError::create(
            sprintf(
                'Invalid type "%s": %s expects exactly %s, got %d',
                $node,
                $node->name,
                self::spellArguments($expected),
                $given,
            ),
            ($args[$expected] ?? $args[0])->location->to($last->location),
        );
    }

    /**
     * How many arguments a message asks for. Arities are small and fixed—no constructor takes more than two—so the
     * counts are spelled out rather than printed as digits, which is how these messages have always read, and the
     * number and its plural are chosen together rather than agreed on by two separate expressions.
     *
     * @param positive-int $count
     */
    private static function spellArguments(int $count): string
    {
        return match ($count) {
            1 => 'one argument',
            2 => 'two arguments',
            default => sprintf('%d arguments', $count),
        };
    }

    /**
     * A function type and a struct type are each their own node class, so they're told apart and dispatched before
     * anything else here asks what $node is named: {@see FunctionTypeNode} is the only shape {@see TypeConstructor::Fn}
     * is ever the name of, so there is nothing left for that case to do below, and a struct has no name at all, so it
     * has no {@see TypeConstructor} case to be found by one.
     *
     * Otherwise: a name the language spells itself is one of the other {@see TypeConstructor}s; the name a `fn<...>`
     * binder enclosing this node introduced is a type variable; anything else is a consumer's alias, or nothing at
     * all.
     */
    public function resolve(TypeNode $node): Type|TypeError
    {
        if ($node instanceof FunctionTypeNode) {
            return $this->resolveFunction($node);
        }
        if ($node instanceof StructTypeNode) {
            return $this->resolveStruct($node);
        }
        if (array_key_exists($node->name, $this->typeVariables)) {
            // A variable stands for one complete type, so like an alias it takes no arguments of its own.
            return self::checkArity($node, 0) ?? Type::var($node->name);
        }
        $constructor = TypeConstructor::tryFrom($node->name);
        if ($constructor === null) {
            return $this->resolveAlias($node) ?? TypeError::create(
                sprintf('Unknown type %s', $node->name),
                $node->location,
            );
        }
        $arity = $constructor->typeArgumentCount();
        $arityError = $arity === null ? null : self::checkArity($node, $arity);
        if ($arityError !== null) {
            return $arityError;
        }
        return match ($constructor) {
            // Unreachable: a node named fn is always a FunctionTypeNode, handled above before $constructor is even
            // looked at. The arm still has to be here for the match over TypeConstructor to be exhaustive.
            TypeConstructor::Fn => throw new LogicException('A node named fn must be a FunctionTypeNode'),
            TypeConstructor::String => Type::string(),
            TypeConstructor::Int => Type::int(),
            TypeConstructor::Float => Type::float(),
            TypeConstructor::Bool => Type::bool(),
            TypeConstructor::Any => Type::any(),
            TypeConstructor::Map => $this->resolveMap($node),
            TypeConstructor::List => $this->resolveList($node),
            TypeConstructor::Option => $this->resolveOption($node),
            TypeConstructor::Some => $this->resolveSome($node),
            TypeConstructor::None => Type::none(),
        };
    }

    private function resolveList(TypeNode $node): Type|TypeError
    {
        assert(count($node->args) === 1);
        $valueType = $this->resolve($node->args[0]);
        return $valueType instanceof TypeError ? $valueType : Type::listOf($valueType);
    }

    private function resolveMap(TypeNode $node): Type|TypeError
    {
        assert(count($node->args) === 2);
        $args = $node->args;
        $keyType = $this->resolve($args[0]);
        if ($keyType instanceof TypeError) {
            return $keyType;
        }
        if (!$keyType->equals(Type::int()) && !$keyType->equals(Type::string())) {
            return TypeError::create(
                sprintf(
                    'Invalid type "%s": map expects the key type to be int or string, got %s',
                    $node,
                    $keyType,
                ),
                $args[0]->location,
            );
        }
        $valueType = $this->resolve($args[1]);
        return $valueType instanceof TypeError ? $valueType : Type::mapOf($keyType, $valueType);
    }

    /**
     * An alias is a name for one complete type, so like the argument-less built-ins, it rejects type arguments instead
     * of silently dropping them—`Foo<int>` is as invalid as `int<string>`. {@see TypeParser::parse()} counts on that:
     * it reads a closed argument list after any name and leaves rejecting it to this resolver.
     */
    private function resolveAlias(TypeNode $node): Type|TypeError|null
    {
        $type = $this->aliases[$node->name] ?? null;
        if ($type === null) {
            return null;
        }
        return self::checkArity($node, 0) ?? Type::alias($node->name, $type);
    }

    /**
     * An Option is a Some that may be absent, so it is the type of its argument and nothing more—which is what
     * {@see self::resolveSome()} already resolves.
     */
    private function resolveOption(TypeNode $node): Type|TypeError
    {
        $some = $this->resolveSome($node);
        return $some instanceof TypeError ? $some : Type::option($some);
    }

    private function resolveSome(TypeNode $node): Type|TypeError
    {
        assert(count($node->args) === 1);
        return $this->resolve($node->args[0]);
    }

    /**
     * A function type is the one type that binds names of its own: the `<T, U>` in front of its parameters says which
     * of the names inside it the call site decides rather than the declaration. The binder is in scope for the
     * parameters and the return type alike, so a fresh resolution with the binder's names added is what resolves both.
     *
     * A binder only makes sense at the top of the signature it quantifies—see {@see Type::var()}—so $node is rejected
     * outright if it has one of its own while already nested inside another function type's parameters or return
     * type. Without that rule, a nested `fn<...>` would parse into a {@see Type} with nowhere to record where the
     * binder was written, and whichever signature encloses the whole type would silently inherit it instead.
     */
    private function resolveFunction(FunctionTypeNode $node): Type|TypeError
    {
        if ($this->nestedInFunction && $node->typeParameters !== []) {
            $first = $node->typeParameters[0];
            $last = $node->typeParameters[array_key_last($node->typeParameters)];
            return TypeError::create(
                'A function type nested inside another one can\'t bind type variables of its own: a variable is '
                    . 'quantified once, by whichever function type encloses it',
                $first->location->to($last->location),
            );
        }
        $typeVariables = $this->typeVariables;
        foreach ($node->typeParameters as $parameter) {
            $error = $this->checkTypeVariable($parameter, $typeVariables);
            if ($error !== null) {
                return $error;
            }
            $typeVariables[$parameter->name] = true;
        }
        $inner = new self($this->aliases, $typeVariables, nestedInFunction: true);
        $argTypes = [];
        foreach ($node->args as $arg) {
            $argType = $inner->resolve($arg);
            if ($argType instanceof TypeError) {
                return $argType;
            }
            $argTypes[] = $argType;
        }
        $returnType = $inner->resolve($node->returnType);
        if ($returnType instanceof TypeError) {
            return $returnType;
        }
        return Type::func($returnType, $argTypes);
    }

    private function resolveStruct(StructTypeNode $node): Type|TypeError
    {
        $fields = [];
        foreach ($node->fields as $field) {
            $type = $this->resolve($field->fieldType);
            if ($type instanceof TypeError) {
                return $type;
            }
            $fields[$field->fieldName->name] = $type;
        }
        return Type::struct($fields);
    }

    /**
     * A binder introduces a name, so the name has to be free to introduce: one the language already spells is a type
     * rather than a placeholder for one, one this same binder already declared would make two parameters answer to
     * the same name, and one an alias already names is a type just as much as a built-in constructor is. An enclosing
     * binder is never in question here—{@see self::resolveFunction()} rejects a nested one before it ever declares a
     * name to collide with.
     *
     * @param array<string, true> $typeVariables The names declared so far in the binder $parameter belongs to, which
     *     grows as {@see self::resolveFunction()} works through the binder's parameters, so that two variables in one
     *     binder can't answer to the same name.
     */
    private function checkTypeVariable(Identifier $parameter, array $typeVariables): TypeError|null
    {
        if (TypeConstructor::tryFrom($parameter->name) !== null) {
            return TypeError::create(TypeConstructor::reservedNameMessage($parameter->name), $parameter->location);
        }
        if (array_key_exists($parameter->name, $typeVariables)) {
            return TypeError::create(
                sprintf('Type variable %s is already declared', $parameter->name),
                $parameter->location,
            );
        }
        if (array_key_exists($parameter->name, $this->aliases)) {
            return TypeError::create(
                sprintf('%s can\'t be a type variable: it already names a type', $parameter->name),
                $parameter->location,
            );
        }
        return null;
    }
}
