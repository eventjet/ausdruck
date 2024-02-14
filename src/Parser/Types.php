<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Parser;

use Eventjet\Ausdruck\Type;
use Eventjet\Ausdruck\Type\AbstractType;

use function array_key_last;
use function count;
use function sprintf;

/**
 * @api
 */
final class Types
{
    /**
     * @param array<string, Type<mixed>> $aliases
     */
    public function __construct(private readonly array $aliases = [])
    {
    }

    /**
     * @template T
     * @param Type<T> $type
     * @return Type<T> | TypeError
     */
    private static function noArgs(Type $type, TypeNode $node): Type|TypeError
    {
        if ($node->args === []) {
            return $type;
        }
        $location = $node->args[0]->location->to($node->args[count($node->args) - 1]->location);
        /** @psalm-suppress ImplicitToStringCast */
        return TypeError::create(sprintf('Invalid type "%s": %s does not accept arguments', $node, $type), $location);
    }

    private static function dummySpan(): Span
    {
        /** @infection-ignore-all These dummy spans are just there to fill parameter lists */
        return Span::char(1, 1);
    }

    /**
     * @return AbstractType<mixed> | TypeError
     */
    public function resolve(TypeNode $node): AbstractType|TypeError
    {
        return match ($node->name) {
            'string' => self::noArgs(Type::string(), $node),
            'int' => self::noArgs(Type::int(), $node),
            'float' => self::noArgs(Type::float(), $node),
            'bool' => self::noArgs(Type::bool(), $node),
            'any' => self::noArgs(Type::any(), $node),
            'map' => $this->resolveMap($node),
            'list' => $this->resolveList($node),
            'Option' => $this->resolveOption($node),
            default => $this->resolveAlias($node->name) ?? TypeError::create(
                sprintf('Unknown type %s', $node->name),
                $node->location,
            ),
        };
    }

    /**
     * @return Type<list<mixed>> | TypeError
     */
    private function resolveList(TypeNode $node): Type|TypeError
    {
        $args = $node->args;
        if ($args === []) {
            return TypeError::create('The list type requires one argument, none given', $node->location);
        }
        $nArgs = count($args);
        if ($nArgs > 1) {
            $location = $args[0]->location->to($args[count($args) - 1]->location);
            /** @psalm-suppress ImplicitToStringCast */
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

    /**
     * @return Type<array<array-key, mixed>> | TypeError
     */
    private function resolveMap(TypeNode $node): Type|TypeError
    {
        $args = $node->args;
        if ($args === []) {
            return TypeError::create('The map type requires two arguments, none given', $node->location);
        }
        $nArgs = count($args);
        if ($nArgs !== 2) {
            /** @psalm-suppress TypeDoesNotContainNull False positive */
            $location = $args[0]->location->to($args[count($args) - 1]->location);
            /** @psalm-suppress ImplicitToStringCast */
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
            /** @psalm-suppress ImplicitToStringCast */
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
        /** @phpstan-ignore-next-line False positive */
        return Type::mapOf($keyType, $valueType);
    }

    /**
     * @return Type<mixed> | null
     */
    private function resolveAlias(string $name): Type|null
    {
        $type = $this->aliases[$name] ?? null;
        if ($type === null) {
            return null;
        }
        return Type::alias($name, $type);
    }

    /**
    * @return Type<mixed> | TypeError
     */
    private function resolveOption(TypeNode $node): Type|TypeError
    {
        if ($node->args === []) {
            return TypeError::create('The Option type requires one argument, none given', $node->location);
        }
        if (count($node->args) > 1) {
            return TypeError::create(
                sprintf('Invalid type "%s": Option expects exactly one argument, got %d', $node, count($node->args)),
                $node->args[1]->location->to($node->args[array_key_last($node->args)]->location),
            );
        }
        $someType = $this->resolve($node->args[0]);
        if ($someType instanceof TypeError) {
            return $someType;
        }
        return Type::option($someType);
    }
}
