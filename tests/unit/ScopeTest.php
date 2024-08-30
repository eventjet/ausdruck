<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Test\Unit;

use Eventjet\Ausdruck\Scope;
use LogicException;
use PHPUnit\Framework\TestCase;

use function fopen;

final class ScopeTest extends TestCase
{
    /**
     * @return iterable<array-key, array{Scope, string}>
     */
    public static function debugCases(): iterable
    {
        yield [new Scope(), '{}'];
        yield [new Scope(['foo' => 'bar']), '{"vars": {"foo": "bar"}}'];
        yield [
            new Scope(['foo' => 'bar'], parent: new Scope(['bar' => 'baz'])),
            '{"vars": {"foo": "bar"}, "parent": {"vars": {"bar": "baz"}}}',
        ];
        yield [new Scope([], parent: new Scope(['bar' => 'baz'])), '{"parent": {"vars": {"bar": "baz"}}}'];
        yield [new Scope(['foo' => true]), '{"vars": {"foo": true}}'];
        yield [new Scope(['foo' => false]), '{"vars": {"foo": false}}'];
        yield [new Scope(['foo' => 1]), '{"vars": {"foo": 1}}'];
        yield [new Scope(['foo' => null]), '{"vars": {"foo": null}}'];
        yield [new Scope(['foo' => (object)['name' => 'John']]), '{"vars": {"foo": {"name": "John"}}}'];
        yield [new Scope(['foo' => fopen('php://temp', 'r')]), '{"vars": {"foo": "resource (stream)"}}'];
    }

    /**
     * @dataProvider debugCases
     */
    public function testDebug(Scope $scope, string $expected): void
    {
        self::assertSame($expected, $scope->debug());
    }

    public function testVariablesCanNotBeShadowed(): void
    {
        $a = new Scope(['foo' => 'bar']);
        $b = new Scope([], parent: $a);

        $this->expectException(LogicException::class);

        new Scope(['other' => 'test', 'foo' => 'bar'], parent: $b);
    }

    public function testBuiltinFunctionsCanNotBeShadowed(): void
    {
        $this->expectException(LogicException::class);

        new Scope([], ['contains' => static fn() => true]);
    }

    public function testCanNotShadowAncestorFunctionA(): void
    {
        $a = new Scope([], ['foo' => static fn() => true]);
        $b = new Scope([], [], $a);

        $this->expectException(LogicException::class);

        new Scope([], ['bar' => static fn() => false, 'foo' => static fn() => true], $b);
    }

    public function testCanNotShadowAncestorFunctionB(): void
    {
        $a = new Scope([], ['foo' => static fn() => true]);
        $b = new Scope([], [], $a);

        $this->expectException(LogicException::class);

        new Scope([], ['foo' => static fn() => true], $b);
    }
}
