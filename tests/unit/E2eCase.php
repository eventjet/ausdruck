<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Test\Unit;

use Eventjet\Ausdruck\AbstractLiteral;
use Eventjet\Ausdruck\Parser\ExpressionParser;
use Eventjet\Ausdruck\Parser\Peekable;
use Eventjet\Ausdruck\Parser\Token;
use Eventjet\Ausdruck\Parser\Tokenizer;
use Eventjet\Ausdruck\Parser\TypeError;
use Eventjet\Ausdruck\Parser\TypeNode;
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
use function explode;
use function file_get_contents;
use function implode;
use function is_string;
use function sprintf;
use function str_ends_with;
use function str_replace;
use function str_split;
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
     * A Types section is a sequence of `Name: <type>` declarations. They're read off a single token stream, because
     * that's what the type parser works on: it stops when the type is complete and leaves the stream on the next
     * declaration's name. Each type is resolved against the aliases declared before it, so `Bag` can be a struct of
     * `Item`s.
     *
     * @return array<string, Type>
     */
    private static function parseTypes(string $src): array
    {
        $tokens = new Peekable(Tokenizer::tokenize($src === '' ? [] : str_split($src)));
        $aliases = [];
        while (true) {
            $name = $tokens->next();
            if ($name === null) {
                return $aliases;
            }
            if (!is_string($name->token)) {
                throw new RuntimeException(sprintf('Expected a type name, got %s', Token::print($name->token)));
            }
            $colon = $tokens->next();
            if ($colon?->token !== Token::Colon) {
                throw new RuntimeException(sprintf('Expected a colon after the type name %s', $name->token));
            }
            /**
             * @psalm-suppress InternalClass
             * @psalm-suppress InternalMethod
             */
            $node = TypeParser::parse($tokens);
            if (!$node instanceof TypeNode) {
                throw new RuntimeException(sprintf('Expected a type for %s', $name->token));
            }
            $type = (new Types($aliases))->resolve($node);
            if ($type instanceof TypeError) {
                throw $type;
            }
            $aliases[$name->token] = $type;
        }
    }
}
