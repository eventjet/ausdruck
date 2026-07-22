<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Parser;

use Eventjet\Ausdruck\Signature;
use Eventjet\Ausdruck\Type;
use Eventjet\Ausdruck\TypeConstructor;

use function array_fill_keys;
use function array_key_exists;
use function array_key_last;
use function array_map;
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
 * reachable from another one's parameters or return type—however many lists, Options, or struct fields deep—can
 * never carry a binder of its own: {@see self::resolveSignature()} rejects one outright rather than letting an inner
 * `fn<...>` shadow or extend the outer scope. Rejecting it is $nestedInSignature's one job—resolving a signature
 * doesn't otherwise ask whether it's nested—so it stays set through every constructor below the enclosing
 * `fn`—list, Option, struct field, and any function type found there—not just a directly-nested function type,
 * which is how the rejection reaches all of them: it is carried by $inner in {@see self::resolveSignature()}, the
 * same instance every one of those constructors recurses back through, rather than being recomputed at each level.
 * $typeVariables itself only ever grows once, when the one binder a signature is allowed to have is resolved.
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
        private readonly bool $nestedInSignature = false,
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
    private static function checkArity(ApplicationTypeNode $node, int $expected): TypeError|null
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
     * The first name $typeParameters declares that $binder doesn't also derive for itself, or null if every declared
     * name is put to use. A binder is written, not inferred, so a name that appears in it has to earn its place the
     * same way any other written thing does.
     *
     * @param list<Identifier> $typeParameters
     * @param list<string> $binder
     */
    private static function firstUnused(array $typeParameters, array $binder): Identifier|null
    {
        if ($typeParameters === []) {
            return null;
        }
        $derived = array_fill_keys($binder, true);
        foreach ($typeParameters as $parameter) {
            if (!array_key_exists($parameter->name, $derived)) {
                return $parameter;
            }
        }
        return null;
    }

    /**
     * Delegates outright, the same way {@see Types::resolve()} delegates to this class: which of
     * {@see self::resolveApplication()}, {@see self::resolveSignature()} or {@see self::resolveStruct()} answers is
     * {@see TypeNode::resolveWith()}'s call, one method per subclass rather than an `instanceof` chain repeated here
     * -- see there for why.
     */
    public function resolve(TypeNode $node): Type|TypeError
    {
        return $node->resolveWith($this);
    }

    /**
     * $node is the only one of the three {@see TypeNode} subclasses with a $name to ask about at all -- a function
     * type is {@see self::resolveSignature()}'s own shape, and a struct type has no name -- so this is the one place
     * a name the language spells itself is told apart from the name a `fn<...>` binder enclosing this node
     * introduced as a type variable, from a consumer's alias, or from nothing at all.
     */
    public function resolveApplication(ApplicationTypeNode $node): Type|TypeError
    {
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
        $arityError = self::checkArity($node, $constructor->typeArgumentCount());
        if ($arityError !== null) {
            return $arityError;
        }
        return match ($constructor) {
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

    /**
     * A function type is the one type that binds names of its own: the `<T, U>` in front of its parameters says which
     * of the names inside it the call site decides rather than the declaration. The binder is in scope for the
     * parameters and the return type alike, so a fresh resolution with the binder's names added is what resolves both.
     *
     * $node is rejected outright if it writes a binder of its own while $this->nestedInSignature is already true --
     * see this class's own docblock for why a nested function type can never legally have one. That rejection is
     * $nestedInSignature's one job: once past it, $node's own written binder -- even empty, if it wrote none at all,
     * which a rejected nested one always does -- is what {@see Signature::written()} stores, the same door whether
     * this resolution is nested or not.
     *
     * $node->typeParameters is what's written, not what {@see Signature::freeVariables()} would find from how the
     * result is actually used, so the two are checked against each other once the signature is built: a name written
     * here that doesn't appear in a parameter or the return type is declared for nothing, and rejected rather than
     * quietly accepted -- see {@see self::firstUnused()}.
     *
     * Public, not private, so {@see FunctionTypeNode::resolveWith()} can call back into it: see {@see self::resolve()}.
     */
    public function resolveSignature(FunctionTypeNode $node): Type|TypeError
    {
        if ($this->nestedInSignature && $node->typeParameters !== []) {
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
        $inner = new self($this->aliases, $typeVariables, nestedInSignature: true);
        $argTypes = [];
        foreach ($node->parameters as $parameter) {
            $argType = $inner->resolve($parameter);
            if ($argType instanceof TypeError) {
                return $argType;
            }
            $argTypes[] = $argType;
        }
        $returnType = $inner->resolve($node->returnType);
        if ($returnType instanceof TypeError) {
            return $returnType;
        }
        // Which variables $returnType and $argTypes reach, not what a Signature's own binder is -- purely for the
        // error below, which needs a written parameter's own location to point at. The binder the Signature below
        // is built with is $node's own written one, not this walk's result.
        $free = Signature::freeVariables($returnType, $argTypes);
        $unused = self::firstUnused($node->typeParameters, $free);
        if ($unused !== null) {
            return TypeError::create(
                sprintf(
                    '%s is declared but doesn\'t appear in the parameters or the return type, so no call could ever decide it',
                    $unused->name,
                ),
                $unused->location,
            );
        }
        $binder = array_map(static fn(Identifier $parameter): string => $parameter->name, $node->typeParameters);
        return Signature::written($returnType, $argTypes, $binder)->toType();
    }

    /**
     * Public, not private, so {@see StructTypeNode::resolveWith()} can call back into it: see {@see self::resolve()}.
     */
    public function resolveStruct(StructTypeNode $node): Type|TypeError
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

    private function resolveList(ApplicationTypeNode $node): Type|TypeError
    {
        assert(count($node->args) === 1);
        $valueType = $this->resolve($node->args[0]);
        return $valueType instanceof TypeError ? $valueType : Type::listOf($valueType);
    }

    private function resolveMap(ApplicationTypeNode $node): Type|TypeError
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
     *
     * $this->aliases already holds every entry wrapped through {@see Type::alias()} -- {@see Types::__construct()}
     * does that once, for each one -- so there is nothing left for a reference to wrap again on top of it.
     */
    private function resolveAlias(ApplicationTypeNode $node): Type|TypeError|null
    {
        $type = $this->aliases[$node->name] ?? null;
        if ($type === null) {
            return null;
        }
        return self::checkArity($node, 0) ?? $type;
    }

    /**
     * An Option is a Some that may be absent, so it is the type of its argument and nothing more—which is what
     * {@see self::resolveSome()} already resolves.
     */
    private function resolveOption(ApplicationTypeNode $node): Type|TypeError
    {
        $some = $this->resolveSome($node);
        return $some instanceof TypeError ? $some : Type::option($some);
    }

    private function resolveSome(ApplicationTypeNode $node): Type|TypeError
    {
        assert(count($node->args) === 1);
        return $this->resolve($node->args[0]);
    }

    /**
     * A binder introduces a name, so the name has to be free to introduce: one the language already spells is a type
     * rather than a placeholder for one, one this same binder already declared would make two parameters answer to
     * the same name, and one an alias already names is a type just as much as a built-in constructor is. An enclosing
     * binder is never in question here—{@see self::resolveSignature()} rejects a nested one before it ever declares a
     * name to collide with.
     *
     * Every name {@see TypeConstructor::isReservedName()} reserves is the one restriction that belongs here for a
     * reason specific to this written text: within the signature this binder introduces the name for, the bare word
     * could no longer mean the type once it also means the variable. {@see Type::var()} rejects the same names too,
     * but for a different reason, so this calls the same helper directly rather than reimplementing it -- the one
     * way the two can't drift apart.
     *
     * @param array<string, true> $typeVariables The names declared so far in the binder $parameter belongs to, which
     *     grows as {@see self::resolveSignature()} works through the binder's parameters, so that two variables in
     *     one binder can't answer to the same name.
     */
    private function checkTypeVariable(Identifier $parameter, array $typeVariables): TypeError|null
    {
        if (TypeConstructor::isReservedName($parameter->name)) {
            return TypeError::create(
                TypeConstructor::reservedNameMessage($parameter->name),
                $parameter->location,
            );
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
