<?php

declare(strict_types=1);

use Eventjet\Ausdruck\Parser\ExpressionParser;
use Eventjet\Ausdruck\Test\Unit\ExpressionTest;
use Eventjet\Ausdruck\Transpilation\Php\PhpTranspiler;
use PhpParser\PrettyPrinter\Standard;

require_once __DIR__ . '/vendor/autoload.php';

$prettyPrinter = new Standard(['shortArraySyntax' => true]);

foreach (ExpressionTest::evaluateCases() as [$expression, , $declarations]) {
    if (is_callable($expression)) {
        $expression = $expression();
    } elseif (is_string($expression)) {
        $expression = ExpressionParser::parse($expression, $declarations);
    }
    try {
        $phpExpr = PhpTranspiler::transpile($expression);
        $php = $prettyPrinter->prettyPrint([$phpExpr]);
    } catch (Throwable $e) {
        $php = $e->getMessage();
    }
    $template = <<<EOF
        Ausdruck: %s
        PHP:      %s
        
        
        EOF;
    echo sprintf($template, $expression, $php);
}
