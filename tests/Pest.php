<?php

use JsonataPhp\Evaluator;
use JsonataPhp\ExpressionService;
use JsonataPhp\Formatters\IntegerFormatter;
use JsonataPhp\Formatters\NumberFormatter;
use JsonataPhp\Functions;
use JsonataPhp\Lexer;
use JsonataPhp\Parser;

function jsonata_test_resolve(string $class): object
{
    return match ($class) {
        ExpressionService::class => new ExpressionService,
        Lexer::class => new Lexer,
        Parser::class => new Parser,
        Evaluator::class => new Evaluator(
            new Functions(
                new Lexer,
                new Parser,
                new IntegerFormatter,
                new NumberFormatter
            )
        ),
        default => new $class,
    };
}

function package_path(string $path = ''): string
{
    $root = dirname(__DIR__);

    if ($path === '' || $path === '.') {
        return $root;
    }

    return $root.'/'.ltrim($path, '/');
}
