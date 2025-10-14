<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Test\Unit;

use Eventjet\Ausdruck\AbstractLiteral;
use Eventjet\Ausdruck\Parser\ExpressionParser;
use Eventjet\Ausdruck\Parser\SyntaxError;
use Eventjet\Ausdruck\Parser\TypeError;
use Eventjet\Ausdruck\Parser\TypeParser;
use Eventjet\Ausdruck\Parser\Types;
use Eventjet\Ausdruck\StructLiteral;
use Eventjet\Ausdruck\Type;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

use function array_key_exists;
use function array_splice;
use function count;
use function explode;
use function file_get_contents;
use function implode;
use function sprintf;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;
use function trim;

use const DIRECTORY_SEPARATOR;

final readonly class E2eCase
{
    private const ROOT = __DIR__ . DIRECTORY_SEPARATOR . 'cases/';

    /**
     * @param array<string, mixed> $input
     */
    public function __construct(
        public string $source,
        public mixed $expected,
        public array $input = [],
        public Types $types = new Types(),
    ) {
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
        $sectionLines = ['Source' => []];
        $section = null;
        foreach (explode("\n", $contents) as $line) {
            if (str_starts_with($line, '-- ') && str_ends_with($line, ' --')) {
                if ($sectionLines['Source'] === []) {
                    throw new RuntimeException('Test file must start with a source section');
                }
                $section = substr($line, 3, -3);
                continue;
            }
            if ($section === null) {
                $sectionLines['Source'][] = $line;
                continue;
            }
            if (!array_key_exists($section, $sectionLines)) {
                $sectionLines[$section] = [];
            }
            $sectionLines[$section][] = $line;
        }
        $sections = [];
        foreach ($sectionLines as $name => $lines) {
            $sections[$name] = implode("\n", $lines);
        }
        $output = ExpressionParser::parse($sections['Output']);
        if (!$output instanceof AbstractLiteral) {
            throw new RuntimeException(sprintf('Output section must be a literal, got %s', $output));
        }
        /** @var mixed $output */
        $output = $output->value();
        if (array_key_exists('Input', $sections)) {
            $inputStruct = ExpressionParser::parse($sections['Input']);
            if (!$inputStruct instanceof StructLiteral) {
                throw new RuntimeException('Input section must be a struct literal');
            }
            /** @var array<string, mixed> $input */
            $input = (array)$inputStruct->value();
        } else {
            $input = [];
        }
        $types = [];
        if (array_key_exists('Types', $sections)) {
            $types = self::parseTypes($sections['Types']);
        }
        return new self($sections['Source'], $output, $input, new Types($types));
    }

    /**
     * @return array<string, Type>
     */
    private static function parseTypes(string $src): array
    {
        $aliases = [];
        $types = new Types();
        while (true) {
            $parts = explode(':', $src, 2);
            if (count($parts) !== 2) {
                break;
            }
            [$name, $src] = $parts;
            /**
             * @psalm-suppress InternalClass
             * @psalm-suppress InternalMethod
             */
            $node = TypeParser::parseString($src);
            if ($node instanceof SyntaxError) {
                throw $node;
            }
            $type = $types->resolve($node);
            if ($type instanceof TypeError) {
                throw $type;
            }
            $aliases[trim($name)] = $type;
            $types = new Types($aliases);
            $lines = explode("\n", $src);
            array_splice($lines, 0, $node->location->endLine);
            $src = implode("\n", $lines);
        }
        return $aliases;
    }
}
