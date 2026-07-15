<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Test\Unit;

use Eventjet\Ausdruck\BuiltinFunctions;
use Eventjet\Ausdruck\Parser\Declarations;
use Eventjet\Ausdruck\Scope;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function array_keys;
use function explode;
use function file_get_contents;
use function preg_match;
use function sort;
use function sprintf;
use function str_starts_with;
use function trim;

/**
 * Guards the single source of truth for built-in functions against drift: {@see Scope} (implementations),
 * {@see Declarations} (parse-time signatures) and the README table must all agree with {@see BuiltinFunctions::all()}.
 */
final class BuiltinFunctionsTest extends TestCase
{
    /**
     * Extracts the function names from the first column of the "Built-In Functions" table in the README.
     *
     * @return list<string>
     */
    private static function readmeBuiltinNames(): array
    {
        $readme = file_get_contents(__DIR__ . '/../../README.md');
        if ($readme === false) {
            throw new RuntimeException('Could not read README.md');
        }
        $names = [];
        $inSection = false;
        foreach (explode("\n", $readme) as $line) {
            if (str_starts_with($line, '#### ')) {
                $inSection = trim($line) === '#### Built-In Functions';
                continue;
            }
            if (!$inSection || !str_starts_with($line, '|')) {
                continue;
            }
            $firstCell = trim(explode('|', $line)[1]);
            // Skip the header row and the |---| separator row.
            if ($firstCell === 'Function' || preg_match('/^-+$/', $firstCell) === 1) {
                continue;
            }
            $names[] = trim($firstCell, '`');
        }
        return $names;
    }

    public function testEveryBuiltinResolvesToACallableInARootScope(): void
    {
        $scope = new Scope();

        foreach (array_keys(BuiltinFunctions::all()) as $name) {
            self::assertNotNull($scope->func($name), sprintf('Built-in "%s" has no implementation in Scope', $name));
        }
    }

    public function testDeclarationsExposeExactlyTheBuiltinsWithADeclaredType(): void
    {
        $expected = [];
        foreach (BuiltinFunctions::all() as $name => $fn) {
            if ($fn['type'] === null) {
                continue;
            }
            $expected[] = $name;
        }
        sort($expected);

        $declared = array_keys((new Declarations())->functions);
        sort($declared);

        self::assertSame($expected, $declared);
    }

    public function testReadmeDocumentsExactlyTheBuiltinFunctions(): void
    {
        $documented = self::readmeBuiltinNames();
        sort($documented);

        $expected = array_keys(BuiltinFunctions::all());
        sort($expected);

        self::assertSame($expected, $documented, 'The README built-in table has drifted from BuiltinFunctions::all()');
    }
}
