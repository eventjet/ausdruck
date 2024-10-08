<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Parser;

use Eventjet\Ausdruck\Type;

use function array_key_last;
use function array_pop;
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

    public function resolve(TypeNode $node): Type|TypeError
    {
        return match ($node->name) {
            'fn' => $this->resolveFunction($node),
            'string' => self::noArgs(Type::string(), $node),
            'int' => self::noArgs(Type::int(), $node),
            'float' => self::noArgs(Type::float(), $node),
            'bool' => self::noArgs(Type::bool(), $node),
            'any' => self::noArgs(Type::any(), $node),
            'map' => $this->resolveMap($node),
            'list' => $this->resolveList($node),
            'Option' => $this->resolveOption($this->exactlyOneTypeArg($node)),
            'Some' => $this->resolveSome($this->exactlyOneTypeArg($node)),
            'None' => self::noArgs(Type::none(), $node),
            default => $this->resolveAlias($node->name) ?? TypeError::create(
                sprintf('Unknown type %s', $node->name),
                $node->location,
            ),
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

    private function resolveSome(Type|TypeError $arg): Type|TypeError
    {
        return $arg instanceof TypeError ? $arg : Type::some($arg);
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
}
