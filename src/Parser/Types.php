<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Parser;

use Eventjet\Ausdruck\Type;

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

    private static function noArgs(Type $type, TypeNode $node): Type|TypeError
    {
        if ($node->args === []) {
            return $type;
        }
        $location = $node->args[0]->location->to($node->args[count($node->args) - 1]->location);
        return TypeError::create(sprintf('Invalid type "%s": %s does not accept arguments', $node, $type), $location);
    }

    private static function dummySpan(): Span
    {
        /** @infection-ignore-all These dummy spans are just there to fill parameter lists */
        return Span::char(1, 1);
    }

    /**
     * A name the language spells itself is one of the {@see TypeConstructor}s; anything else is a consumer's alias, or
     * nothing at all.
     */
    public function resolve(TypeNode $node): Type|TypeError
    {
        $constructor = TypeConstructor::tryFrom($node->name);
        if ($constructor === null) {
            return $this->resolveAlias($node->name) ?? TypeError::create(
                sprintf('Unknown type %s', $node->name),
                $node->location,
            );
        }
        return match ($constructor) {
            TypeConstructor::Fn => $this->resolveFunction($node),
            TypeConstructor::String => self::noArgs(Type::string(), $node),
            TypeConstructor::Int => self::noArgs(Type::int(), $node),
            TypeConstructor::Float => self::noArgs(Type::float(), $node),
            TypeConstructor::Bool => self::noArgs(Type::bool(), $node),
            TypeConstructor::Any => self::noArgs(Type::any(), $node),
            TypeConstructor::Map => $this->resolveMap($node),
            TypeConstructor::List => $this->resolveList($node),
            TypeConstructor::Option => $this->resolveOption($this->exactlyOneTypeArg($node)),
            TypeConstructor::Some => $this->exactlyOneTypeArg($node),
            TypeConstructor::None => self::noArgs(Type::none(), $node),
            TypeConstructor::Struct => $this->resolveStruct($node),
        };
    }

    private function exactlyOneTypeArg(TypeNode $node): Type|TypeError
    {
        if ($node->args === []) {
            return TypeError::create(
                sprintf('The %s type requires one argument, none given', $node->name),
                $node->location,
            );
        }
        if (count($node->args) > 1) {
            return TypeError::create(
                sprintf(
                    'Invalid type "%s": %s expects exactly one argument, got %d',
                    $node,
                    $node->name,
                    count($node->args),
                ),
                $node->args[1]->location->to($node->args[array_key_last($node->args)]->location),
            );
        }
        return $this->resolve($node->args[0]);
    }

    private function resolveList(TypeNode $node): Type|TypeError
    {
        $args = $node->args;
        if ($args === []) {
            return TypeError::create('The list type requires one argument, none given', $node->location);
        }
        $nArgs = count($args);
        if ($nArgs > 1) {
            $location = $args[0]->location->to($args[count($args) - 1]->location);
            return TypeError::create(
                sprintf(
                    'Invalid type "%s": list expects exactly one argument, got %d',
                    new TypeNode('list', $args, self::dummySpan()),
                    $nArgs,
                ),
                $location,
            );
        }
        $valueType = $this->resolve($args[0]);
        if ($valueType instanceof TypeError) {
            return $valueType;
        }
        return Type::listOf($valueType);
    }

    private function resolveMap(TypeNode $node): Type|TypeError
    {
        $args = $node->args;
        if ($args === []) {
            return TypeError::create('The map type requires two arguments, none given', $node->location);
        }
        $nArgs = count($args);
        if ($nArgs !== 2) {
            $location = $args[0]->location->to($args[count($args) - 1]->location);
            return TypeError::create(
                sprintf(
                    'Invalid type "%s": map expects exactly two arguments, got %d',
                    new TypeNode('map', $args, self::dummySpan()),
                    $nArgs,
                ),
                $location,
            );
        }
        $keyType = $this->resolve($args[0]);
        if ($keyType instanceof TypeError) {
            return $keyType;
        }
        if (!$keyType->equals(Type::int()) && !$keyType->equals(Type::string())) {
            return TypeError::create(
                sprintf(
                    'Invalid type "%s": map expects the key type to be int or string, got %s',
                    new TypeNode('map', $args, self::dummySpan()),
                    $keyType,
                ),
                $args[0]->location,
            );
        }
        $valueType = $this->resolve($args[1]);
        if ($valueType instanceof TypeError) {
            return $valueType;
        }
        return Type::mapOf($keyType, $valueType);
    }

    private function resolveAlias(string $name): Type|null
    {
        $type = $this->aliases[$name] ?? null;
        if ($type === null) {
            return null;
        }
        return Type::alias($name, $type);
    }

    private function resolveOption(Type|TypeError $arg): Type|TypeError
    {
        return $arg instanceof TypeError ? $arg : Type::option($arg);
    }

    private function resolveFunction(TypeNode $node): Type|TypeError
    {
        $args = $node->args;
        if ($args === []) {
            return TypeError::create('The func type requires at least one argument, none given', $node->location);
        }
        $returnType = array_pop($args);
        $argTypes = [];
        foreach ($args as $arg) {
            $argType = $this->resolve($arg);
            if ($argType instanceof TypeError) {
                return $argType;
            }
            $argTypes[] = $argType;
        }
        $returnType = $this->resolve($returnType);
        if ($returnType instanceof TypeError) {
            return $returnType;
        }
        return Type::func($returnType, $argTypes);
    }

    private function resolveStruct(TypeNode $node): Type|TypeError
    {
        $fields = [];
        foreach ($node->args as $field) {
            assert(count($field->args) === 2);
            [$nameNode, $typeNode] = $field->args;
            $type = $this->resolve($typeNode);
            if ($type instanceof TypeError) {
                return $type;
            }
            $fields[$nameNode->name] = $type;
        }
        return Type::struct($fields);
    }
}
