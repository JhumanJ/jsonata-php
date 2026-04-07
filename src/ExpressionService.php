<?php

namespace JsonataPhp;

use JsonataPhp\Formatters\IntegerFormatter;
use JsonataPhp\Formatters\NumberFormatter;

class ExpressionService
{
    public function __construct(
        ?Lexer $lexer = null,
        ?Parser $parser = null,
        ?Evaluator $evaluator = null,
    ) {
        $lexer ??= new Lexer;
        $parser ??= new Parser;
        $evaluator ??= new Evaluator(
            new Functions($lexer, $parser, new IntegerFormatter, new NumberFormatter)
        );

        $this->lexer = $lexer;
        $this->parser = $parser;
        $this->evaluator = $evaluator;
    }

    private readonly Lexer $lexer;

    private readonly Parser $parser;

    private readonly Evaluator $evaluator;

    public function evaluate(string $expression, mixed $context): mixed
    {
        $tokens = $this->lexer->tokenize($expression);
        $ast = $this->parser->parse($tokens);
        $rootContext = is_array($context) ? $context : ['value' => $context];

        return $this->evaluator->evaluateWithContext($ast, $context, $rootContext);
    }
}
