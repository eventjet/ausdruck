<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Parser;

use Eventjet\Ausdruck\Type;

use function array_key_exists;
use function array_key_last;
use function array_pop;
use function assert;
use function count;
use function sprintf;

/**
 * @api
 */
final class Types
{
    /**
     * @param array<string, Type> $aliases
     */
    public function __construct(private readonly array $aliases = [])
    {
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
     * A binder introduces a name, so the name has to be free to introduce: one the language already spells is a type
     * rather than a placeholder for one, and one an enclosing binder already introduced would make two different
     * variables answer to the same name.
     *
     * @param array<string, true> $typeVariables
     */
    private static function checkTypeVariable(TypeNode $parameter, array $typeVariables): TypeError|null
    {
        if (TypeConstructor::tryFrom($parameter->name) !== null) {
            return TypeError::create(
                sprintf('%s can\'t be a type variable: it is a type of its own', $parameter->name),
                $parameter->location,
            );
        }
        if (array_key_exists($parameter->name, $typeVariables)) {
            return TypeError::create(
                sprintf('Type variable %s is already declared', $parameter->name),
                $parameter->location,
            );
        }
        return null;
    }

    /**
     * A name the language spells itself is one of the {@see TypeConstructor}s; anything else is a consumer's alias, or
     * nothing at all.
     */
    public function resolve(TypeNode $node): Type|TypeError
    {
        return $this->resolveIn($node, []);
    }

    /**
     * The one name that resolves to neither a constructor nor an alias is a type variable, and it is a type variable
     * only where a `fn<...>` above it binds it—which is what $typeVariables carries down. A binder is the whole of a
     * variable's scope, so a name outside every binder that mentions it is as unknown as it ever was.
     *
     * @param array<string, true> $typeVariables
     */
    private function resolveIn(TypeNode $node, array $typeVariables): Type|TypeError
    {
        if (array_key_exists($node->name, $typeVariables)) {
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
            TypeConstructor::Fn => $this->resolveFunction($node, $typeVariables),
            TypeConstructor::String => Type::string(),
            TypeConstructor::Int => Type::int(),
            TypeConstructor::Float => Type::float(),
            TypeConstructor::Bool => Type::bool(),
            TypeConstructor::Any => Type::any(),
            TypeConstructor::Map => $this->resolveMap($node, $typeVariables),
            TypeConstructor::List => $this->resolveList($node, $typeVariables),
            TypeConstructor::Option => $this->resolveOption($node, $typeVariables),
            TypeConstructor::Some => $this->resolveSome($node, $typeVariables),
            TypeConstructor::None => Type::none(),
            TypeConstructor::Struct => $this->resolveStruct($node, $typeVariables),
        };
    }

    /**
     * @param array<string, true> $typeVariables
     */
    private function resolveList(TypeNode $node, array $typeVariables): Type|TypeError
    {
        assert(count($node->args) === 1);
        $valueType = $this->resolveIn($node->args[0], $typeVariables);
        return $valueType instanceof TypeError ? $valueType : Type::listOf($valueType);
    }

    /**
     * @param array<string, true> $typeVariables
     */
    private function resolveMap(TypeNode $node, array $typeVariables): Type|TypeError
    {
        assert(count($node->args) === 2);
        $args = $node->args;
        $keyType = $this->resolveIn($args[0], $typeVariables);
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
        $valueType = $this->resolveIn($args[1], $typeVariables);
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
     *
     * @param array<string, true> $typeVariables
     */
    private function resolveOption(TypeNode $node, array $typeVariables): Type|TypeError
    {
        $some = $this->resolveSome($node, $typeVariables);
        return $some instanceof TypeError ? $some : Type::option($some);
    }

    /**
     * @param array<string, true> $typeVariables
     */
    private function resolveSome(TypeNode $node, array $typeVariables): Type|TypeError
    {
        assert(count($node->args) === 1);
        return $this->resolveIn($node->args[0], $typeVariables);
    }

    /**
     * A function type is the one type that binds names of its own: the `<T, U>` in front of its parameters says which
     * of the names inside it the call site decides rather than the declaration. The binder is in scope for the
     * parameters and the return type alike, so it is added before either is resolved.
     *
     * @param array<string, true> $typeVariables
     */
    private function resolveFunction(TypeNode $node, array $typeVariables): Type|TypeError
    {
        foreach ($node->typeParameters as $parameter) {
            $error = self::checkTypeVariable($parameter, $typeVariables);
            if ($error !== null) {
                return $error;
            }
            $typeVariables[$parameter->name] = true;
        }
        $args = $node->args;
        if ($args === []) {
            return TypeError::create('The func type requires at least one argument, none given', $node->location);
        }
        $returnType = array_pop($args);
        $argTypes = [];
        foreach ($args as $arg) {
            $argType = $this->resolveIn($arg, $typeVariables);
            if ($argType instanceof TypeError) {
                return $argType;
            }
            $argTypes[] = $argType;
        }
        $returnType = $this->resolveIn($returnType, $typeVariables);
        if ($returnType instanceof TypeError) {
            return $returnType;
        }
        return Type::func($returnType, $argTypes);
    }

    /**
     * @param array<string, true> $typeVariables
     */
    private function resolveStruct(TypeNode $node, array $typeVariables): Type|TypeError
    {
        $fields = [];
        foreach ($node->args as $field) {
            assert(count($field->args) === 2);
            [$nameNode, $typeNode] = $field->args;
            $type = $this->resolveIn($typeNode, $typeVariables);
            if ($type instanceof TypeError) {
                return $type;
            }
            $fields[$nameNode->name] = $type;
        }
        return Type::struct($fields);
    }
}
