<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Test\Unit;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

use function array_key_exists;
use function explode;
use function file_get_contents;
use function implode;
use function json_decode;
use function sprintf;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;

use const DIRECTORY_SEPARATOR;

final readonly class E2eCase
{
    private const ROOT = __DIR__ . DIRECTORY_SEPARATOR . 'cases/';

    /**
     * @param array<string, mixed> $input
     */
    public function __construct(public string $source, public mixed $expected, public array $input = [])
    {
    }

    /**
     * @return iterable<string, self>
     */
    public static function all(): iterable
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::ROOT, FilesystemIterator::SKIP_DOTS));
        /** @var SplFileInfo $path */
        foreach ($iterator as $path) {
            if (!$path->isFile()) {
                continue;
            }
            $name = str_replace(DIRECTORY_SEPARATOR, '/', substr($path->getPathname(), strlen(self::ROOT)));
            yield $name => self::get($name);
        }
    }

    private static function get(string $name): self
    {
        $contents = file_get_contents(self::ROOT . str_replace('/', DIRECTORY_SEPARATOR, $name));
        if ($contents === false) {
            throw new RuntimeException(sprintf('Unknown case %s', $name));
        }
        return self::parse($contents);
    }

    private static function parse(string $contents): self
    {
        $lines = explode("\n", $contents);
        $sections = ['Source' => []];
        $section = null;
        foreach ($lines as $line) {
            if (str_starts_with($line, '-- ') && str_ends_with($line, ' --')) {
                if ($sections['Source'] === []) {
                    throw new RuntimeException('Test file must start with a source section');
                }
                $section = substr($line, 3, -3);
                continue;
            }
            if ($section === null) {
                $sections['Source'][] = $line;
                continue;
            }
            if (!array_key_exists($section, $sections)) {
                $sections[$section] = [];
            }
            $sections[$section][] = $line;
        }
        foreach ($sections as &$section) {
            $section = implode("\n", $section);
        }
        $output = json_decode($sections['Output']);
        if ($output === null) {
            throw new RuntimeException('Invalid JSON in output section');
        }
        if (array_key_exists('Input', $sections)) {
            $input = json_decode($sections['Input'], true);
            if ($input === null) {
                throw new RuntimeException('Invalid JSON in input section');
            }
        } else {
            $input = [];
        }
        return new self($sections['Source'], $output, $input);
    }
}
