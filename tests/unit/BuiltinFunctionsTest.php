<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Test\Unit;

use Eventjet\Ausdruck\BuiltinFunctions;
use Eventjet\Ausdruck\Parser\Declarations;
use Eventjet\Ausdruck\Parser\TypeNode;
use Eventjet\Ausdruck\Parser\TypeParser;
use Eventjet\Ausdruck\Parser\Types;
use Eventjet\Ausdruck\Scope;
use Eventjet\Ausdruck\Type;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function array_key_exists;
use function array_keys;
use function assert;
use function explode;
use function file_get_contents;
use function preg_match;
use function sort;
use function sprintf;
use function str_starts_with;
use function trim;

/**
 * Guards the single source of truth for built-in functions against drift: {@see Scope} (implementations),
 * {@see Declarations} (parse-time signatures) and the README table must all agree with {@see BuiltinFunctions}.
 */
final class BuiltinFunctionsTest extends TestCase
{
    /**
     * The "Built-In Functions" table in the README, read from its first two columns as name => signature.
     *
     * @return array<string, string>
     */
    private static function readmeBuiltins(): array
    {
        $readme = file_get_contents(__DIR__ . '/../../README.md');
        if ($readme === false) {
            throw new RuntimeException('Could not read README.md');
        }
        $builtins = [];
        $inSection = false;
        foreach (explode("\n", $readme) as $line) {
            if (str_starts_with($line, '#### ')) {
                $inSection = trim($line) === '#### Built-In Functions';
                continue;
            }
            if (!$inSection || !str_starts_with($line, '|')) {
                continue;
            }
            $cells = explode('|', $line);
            $firstCell = trim($cells[1]);
            // Skip the header row and the |---| separator row.
            if ($firstCell === 'Function' || preg_match('/^-+$/', $firstCell) === 1) {
                continue;
            }
            if (!array_key_exists(2, $cells)) {
                throw new RuntimeException(sprintf('Built-in table row has no signature column: %s', $line));
            }
            $builtins[trim($firstCell, '`')] = trim(trim($cells[2]), '`');
        }
        return $builtins;
    }

    /**
     * A README signature, read back as the {@see Type} it spells.
     */
    private static function resolveSignature(string $signature): Type
    {
        /**
         * @psalm-suppress InternalClass
         * @psalm-suppress InternalMethod
         */
        $node = TypeParser::parseString($signature);
        assert($node instanceof TypeNode);
        $type = (new Types())->resolve($node);
        assert($type instanceof Type);
        return $type;
    }

    public function testEveryBuiltinResolvesToACallableInARootScope(): void
    {
        $scope = new Scope();

        foreach (array_keys(BuiltinFunctions::implementations()) as $name) {
            self::assertNotNull($scope->func($name), sprintf('Built-in "%s" has no implementation in Scope', $name));
        }
    }

    public function testDeclarationsExposeExactlyTheBuiltinsWithADeclaredType(): void
    {
        $expected = array_keys(BuiltinFunctions::types());
        sort($expected);

        $declared = array_keys((new Declarations())->functions);
        sort($declared);

        self::assertSame($expected, $declared);
    }

    public function testReadmeDocumentsExactlyTheBuiltinFunctions(): void
    {
        $documented = array_keys(self::readmeBuiltins());
        sort($documented);

        $expected = array_keys(BuiltinFunctions::implementations());
        sort($expected);

        self::assertSame($expected, $documented, 'The README built-in table has drifted from the built-in functions');
    }

    /**
     * The README spells every signature the way an expression spells one, `fn<T>(list<T>) -> T`, rather than the way a
     * {@see Type} prints itself. Resolving it is what makes the two comparable, and what keeps the documented signature
     * from drifting away from the declared one.
     */
    public function testReadmeSpellsEveryBuiltinSignatureTheWayItIsDeclared(): void
    {
        $declared = BuiltinFunctions::types();

        foreach (self::readmeBuiltins() as $name => $signature) {
            self::assertSame(
                (string)$declared[$name],
                (string)self::resolveSignature($signature),
                sprintf('The README signature of %s, %s, is not the one it is declared with', $name, $signature),
            );
        }
    }
}
