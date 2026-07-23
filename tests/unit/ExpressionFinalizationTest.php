<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Test\Unit;

use Eventjet\Ausdruck\Expression;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use function sprintf;

/**
 * Guards the shape of {@see Expression} as an extension point: the class stays open and its four extension-point
 * methods stay overridable, but the concrete builder combinators are locked. A subclass adds a node by implementing
 * the abstract methods; it has no legitimate reason to reimplement a combinator, which is a fixed algorithm over the
 * closed, internal node set.
 */
final class ExpressionFinalizationTest extends TestCase
{
    private const CONCRETE_COMBINATORS = [
        'eq',
        'neq',
        'subtract',
        'add',
        'multiply',
        'divide',
        'modulo',
        'gt',
        'lt',
        'gte',
        'lte',
        'or_',
        'and_',
        'not',
        'call',
        'matchesType',
        'isSubtypeOf',
    ];
    private const EXTENSION_POINTS = [
        'location',
        'evaluate',
        'equals',
        'getType',
    ];

    /**
     * @return iterable<string, array{string}>
     */
    public static function concreteCombinatorProvider(): iterable
    {
        foreach (self::CONCRETE_COMBINATORS as $method) {
            yield $method => [$method];
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function extensionPointProvider(): iterable
    {
        foreach (self::EXTENSION_POINTS as $method) {
            yield $method => [$method];
        }
    }

    public function testTheClassStaysExtendable(): void
    {
        $class = new ReflectionClass(Expression::class);

        self::assertFalse($class->isFinal(), 'Expression must stay open as an extension point.');
        self::assertTrue(
            $class->isAbstract(),
            'Expression is an abstract base with methods for a subclass to implement.',
        );
    }

    #[DataProvider('concreteCombinatorProvider')]
    public function testConcreteCombinatorsAreFinal(string $method): void
    {
        self::assertTrue(
            (new ReflectionClass(Expression::class))->getMethod($method)->isFinal(),
            sprintf('Expression::%s() is a fixed algorithm and must be final.', $method),
        );
    }

    #[DataProvider('extensionPointProvider')]
    public function testExtensionPointsStayOverridable(string $method): void
    {
        $reflection = (new ReflectionClass(Expression::class))->getMethod($method);

        self::assertTrue(
            $reflection->isAbstract(),
            sprintf('Expression::%s() is an extension point a subclass implements; it must stay abstract.', $method),
        );
        self::assertFalse(
            $reflection->isFinal(),
            sprintf('Expression::%s() must stay overridable.', $method),
        );
    }
}
