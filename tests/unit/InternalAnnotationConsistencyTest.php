<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Test\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function file_get_contents;
use function implode;
use function sort;
use function sprintf;
use function str_contains;

/**
 * Guards the public API surface convention: the surface is "public and not {@literal @}internal". A symbol carved
 * out of it for the static analyzers with {@literal @}psalm-internal must also carry {@literal @}internal, because
 * the backward-compatibility checker only recognizes {@literal @}internal. Without the pair, the checker treats
 * internal code as public API and either cries wolf on internal churn or demands a needless version bump.
 * See docs/BACKWARD-COMPATIBILITY.md.
 */
final class InternalAnnotationConsistencyTest extends TestCase
{
    public function testEveryPsalmInternalFileIsAlsoMarkedInternal(): void
    {
        $offenders = [];
        foreach ($this->phpSourceFiles() as $file) {
            $contents = file_get_contents($file);
            if ($contents === false) {
                self::fail(sprintf('Could not read source file %s', $file));
            }
            if (str_contains($contents, '@psalm-internal') && !str_contains($contents, '@internal')) {
                $offenders[] = $file;
            }
        }

        self::assertSame([], $offenders, sprintf(
            'Every file carrying @psalm-internal must also carry @internal so the backward-compatibility checker '
            . 'treats it as internal rather than public API. Offenders: %s',
            implode(', ', $offenders),
        ));
    }

    /**
     * @return list<string>
     */
    private function phpSourceFiles(): array
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(__DIR__ . '/../../src', FilesystemIterator::SKIP_DOTS),
        );
        $files = [];
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $files[] = $file->getPathname();
        }
        sort($files);

        return $files;
    }
}
