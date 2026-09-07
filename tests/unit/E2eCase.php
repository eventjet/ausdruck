<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Test\Unit;

use Eventjet\Ausdruck\AbstractLiteral;
use Eventjet\Ausdruck\Formatter\ExpressionFormatter;
use Eventjet\Ausdruck\Parser\Declarations;
use Eventjet\Ausdruck\Parser\ExpressionParser;
use Eventjet\Ausdruck\Parser\SyntaxError;
use Eventjet\Ausdruck\Parser\TypeError;
use Eventjet\Ausdruck\Parser\TypeNode;
use Eventjet\Ausdruck\Parser\Types;
use Eventjet\Ausdruck\StructLiteral;
use Eventjet\Ausdruck\Type;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

use function array_diff;
use function array_key_exists;
use function array_keys;
use function array_map;
use function ctype_digit;
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

/**
 * One case, written as a file under `cases/`.
 *
 * A case file is an expression followed by named sections. The expression is everything before the first `-- Name --`
 * line; each section runs to the next one. What a case asserts is decided by the sections it writes:
 *
 * - `Output` — a literal the expression has to evaluate to, with `Input` supplying the variables it reads.
 * - `Expression type` — the type the expression has, printed. A case may write both, and then has to satisfy both.
 * - `Formatted` — how the expression is spelled back out across lines, laid out to the `Width` section's columns or to
 *   the formatter's default. A case that writes it also asserts that what comes out re-parses to the same expression.
 * - `Syntax error` / `Type error` — the message the source has to be rejected with. Which of the two sections is used
 *   is what says which error is expected, so only the message is written out.
 *
 * `Types` declares the aliases the source may name, and `Functions` the signatures it may call, both as `Name: <type>`
 * declarations. A declared function has a signature but no implementation, so a case that declares one describes what
 * its calls mean rather than what they compute, and can't ask to be evaluated.
 *
 * `Note` asserts nothing: it is where a case says why it exists, for the cases whose name can't carry the whole reason.
 */
final readonly class E2eCase
{
    use ParsesTypeSyntax;

    private const ROOT = __DIR__ . DIRECTORY_SEPARATOR . 'cases/';
    /**
     * The sections that say the source is rejected, and what each of them expects it to be rejected with.
     *
     * @var array<string, class-string<SyntaxError | TypeError>>
     */
    private const ERROR_SECTIONS = ['Syntax error' => SyntaxError::class, 'Type error' => TypeError::class];
    /**
     * Every section a case may write. A name that isn't one of these is a typo, and a typo that went unnoticed would be
     * a case that quietly stopped asserting what it was written to assert.
     *
     * @var list<string>
     */
    private const SECTIONS = [
        'Source',
        'Input',
        'Output',
        'Expression type',
        'Formatted',
        'Width',
        'Types',
        'Functions',
        'Syntax error',
        'Type error',
        'Note',
    ];

    /**
     * @param array<string, mixed> $input
     * @param positive-int $width
     */
    private function __construct(
        public string $source,
        public Declarations $declarations = new Declarations(),
        public AbstractLiteral|null $output = null,
        public array $input = [],
        public string|null $expressionType = null,
        public E2eError|null $error = null,
        public string|null $formatted = null,
        public int $width = ExpressionFormatter::DEFAULT_WIDTH,
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
        $sections = self::split($contents);
        $unknown = array_diff(array_keys($sections), self::SECTIONS);
        if ($unknown !== []) {
            throw new RuntimeException(
                sprintf('Unknown section %s; a case has %s', implode(' and ', $unknown), implode(', ', self::SECTIONS)),
            );
        }
        $types = new Types(array_key_exists('Types', $sections) ? self::parseTypes($sections['Types']) : []);
        $functions = array_key_exists('Functions', $sections)
            ? self::parseFunctions($sections['Functions'], $types)
            : [];
        $output = array_key_exists('Output', $sections) ? self::parseOutput($sections['Output']) : null;
        $expressionType = $sections['Expression type'] ?? null;
        $formatted = $sections['Formatted'] ?? null;
        $error = self::parseError($sections);
        if ($error !== null && ($output !== null || $expressionType !== null || $formatted !== null)) {
            throw new RuntimeException(
                'A case that expects an error can\'t expect an output, a type or a formatting as well: a source that '
                    . 'is rejected has none of them',
            );
        }
        if ($error === null && $output === null && $expressionType === null && $formatted === null) {
            throw new RuntimeException(
                'A case must expect something: an Output, an Expression type, a Formatted, or an error',
            );
        }
        if ($formatted === null && array_key_exists('Width', $sections)) {
            throw new RuntimeException(
                'A Width is how wide the Formatted section was laid out to, so a case writing one writes both',
            );
        }
        if ($output !== null && $functions !== []) {
            throw new RuntimeException(
                'A case that declares functions can\'t be evaluated: a declaration says what a function\'s calls mean, '
                    . 'and there is no implementation here to compute them',
            );
        }
        return new self(
            $sections['Source'],
            new Declarations(types: $types, functions: $functions),
            $output,
            array_key_exists('Input', $sections) ? self::parseInput($sections['Input']) : [],
            $expressionType,
            $error,
            $formatted,
            array_key_exists('Width', $sections)
                ? self::parseWidth($sections['Width'])
                : ExpressionFormatter::DEFAULT_WIDTH,
        );
    }

    /**
     * The number of columns the Formatted section was laid out to. A case writes one so the shape it pins can be shown
     * at the size that provokes it, rather than by padding an expression out to the formatter's default width.
     *
     * Zero columns is rejected along with everything that isn't a number: a width is the last column a line may use,
     * so the narrowest one a case can pin a shape at is one.
     *
     * @return positive-int
     */
    private static function parseWidth(string $src): int
    {
        $width = ctype_digit($src) ? (int)$src : 0;
        if ($width < 1) {
            throw new RuntimeException(sprintf('Width must be a positive number of columns, got %s', $src));
        }
        return $width;
    }

    /**
     * The source is the section before there is one, so it is the section every file has and the only one that isn't
     * named.
     *
     * @return array<string, string>
     */
    private static function split(string $contents): array
    {
        $sectionLines = ['Source' => []];
        $section = null;
        foreach (explode("\n", $contents) as $line) {
            if (str_starts_with($line, '-- ') && str_ends_with($line, ' --')) {
                if ($sectionLines['Source'] === []) {
                    throw new RuntimeException('Test file must start with a source section');
                }
                $section = substr($line, 3, -3);
                $sectionLines[$section] ??= [];
                continue;
            }
            $sectionLines[$section ?? 'Source'][] = $line;
        }
        return array_map(static fn(array $lines): string => trim(implode("\n", $lines)), $sectionLines);
    }

    private static function parseOutput(string $src): AbstractLiteral
    {
        $output = ExpressionParser::parse($src);
        if (!$output instanceof AbstractLiteral) {
            throw new RuntimeException(sprintf('Output section must be a literal, got %s', $output));
        }
        return $output;
    }

    /**
     * @return array<string, mixed>
     */
    private static function parseInput(string $src): array
    {
        $inputStruct = ExpressionParser::parse($src);
        if (!$inputStruct instanceof StructLiteral) {
            throw new RuntimeException('Input section must be a struct literal');
        }
        /** @var array<string, mixed> $input */
        $input = (array)$inputStruct->value();
        return $input;
    }

    /**
     * @param array<string, string> $sections
     */
    private static function parseError(array $sections): E2eError|null
    {
        $error = null;
        foreach (self::ERROR_SECTIONS as $section => $class) {
            if (!array_key_exists($section, $sections)) {
                continue;
            }
            if ($error !== null) {
                throw new RuntimeException('A case expects one error, not both a syntax error and a type error');
            }
            $error = new E2eError($class, $sections[$section]);
        }
        return $error;
    }

    /**
     * A Types section is a sequence of `Name: <type>` declarations. Each type is resolved against the aliases declared
     * before it, so `Bag` can be a struct of `Item`s.
     *
     * @return array<string, Type>
     */
    private static function parseTypes(string $src): array
    {
        $aliases = [];
        foreach (self::parseTypeDeclarations($src) as $name => $node) {
            $aliases[$name] = self::resolve($node, new Types($aliases));
        }
        return $aliases;
    }

    /**
     * A Functions section declares call signatures, in the same shape a Types section declares aliases. Every alias the
     * case declares is in scope for all of them, so a signature can be written in terms of the case's own types no
     * matter which order the two sections are in.
     *
     * @return array<string, Type>
     */
    private static function parseFunctions(string $src, Types $types): array
    {
        $functions = [];
        foreach (self::parseTypeDeclarations($src) as $name => $node) {
            $functions[$name] = self::resolve($node, $types);
        }
        return $functions;
    }

    private static function resolve(TypeNode $node, Types $types): Type
    {
        $type = $types->resolve($node);
        if ($type instanceof TypeError) {
            throw $type;
        }
        return $type;
    }
}
