<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Parser;

use Eventjet\Ausdruck\Type;

use function count;
use function sprintf;

final readonly class Types
{
    /**
     * @param array<string, Type<mixed>> $aliases
     */
    public function __construct(private array $aliases = [])
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
        /** @psalm-suppress ImplicitToStringCast */
        return new TypeError(sprintf('Invalid type "%s": %s does not accept arguments', $node, $type));
    }

    /**
     * @return Type<mixed> | TypeError
     */
    public function resolve(TypeNode $node): Type|TypeError
    {
        return match ($node->name) {
            'string' => self::noArgs(Type::string(), $node),
            'int' => self::noArgs(Type::int(), $node),
            'float' => self::noArgs(Type::float(), $node),
            'bool' => self::noArgs(Type::bool(), $node),
            'any' => self::noArgs(Type::any(), $node),
            'map' => $this->resolveMap($node->args),
            'list' => $this->resolveList($node->args),
            default => $this->resolveAlias($node->name) ?? new TypeError(sprintf('Unknown type %s', $node->name)),
        };
    }

    /**
     * @param list<TypeNode> $args
     * @return Type<list<mixed>> | TypeError
     */
    private function resolveList(array $args): Type|TypeError
    {
        $nArgs = count($args);
        if ($nArgs !== 1) {
            /** @psalm-suppress ImplicitToStringCast */
            return new TypeError(
                sprintf(
                    'Invalid type "%s": list expects exactly one argument, got %d',
                    new TypeNode('list', $args),
                    $nArgs,
                ),
            );
        }
        $valueType = $this->resolve($args[0]);
        if ($valueType instanceof TypeError) {
            return $valueType;
        }
        return Type::listOf($valueType);
    }

    /**
     * @param list<TypeNode> $args
     * @return Type<array<array-key, mixed>> | TypeError
     */
    private function resolveMap(array $args): Type|TypeError
    {
        $nArgs = count($args);
        if ($nArgs !== 2) {
            /** @psalm-suppress ImplicitToStringCast */
            return new TypeError(
                sprintf(
                    'Invalid type "%s": map expects exactly two arguments, got %d',
                    new TypeNode('map', $args),
                    $nArgs,
                ),
            );
        }
        $keyType = $this->resolve($args[0]);
        if ($keyType instanceof TypeError) {
            return $keyType;
        }
        /** @phpstan-ignore-next-line False positive */
        if (!$keyType->equals(Type::int()) && !$keyType->equals(Type::string())) {
            /** @psalm-suppress ImplicitToStringCast */
            return new TypeError(
                sprintf(
                    'Invalid type "%s": map expects the key type to be int or string, got %s',
                    new TypeNode('map', $args),
                    $keyType,
                ),
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
}
