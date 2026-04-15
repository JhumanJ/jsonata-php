<?php

namespace JsonataPhp;

class Parser
{
    /**
     * @param  array<int, array{type: string, value: mixed, position: int}>  $tokens
     * @return array<string, mixed>
     */
    public function parse(array $tokens): array
    {
        $stream = new TokenStream($tokens);
        $ast = $this->parseExpression($stream);
        $stream->expect('eof');

        return $ast;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseExpression(TokenStream $stream): array
    {
        return $this->parseSequence($stream);
    }

    /**
     * @return array<string, mixed>
     */
    private function parseSequence(TokenStream $stream): array
    {
        $expressions = [$this->parseConditional($stream)];

        while ($stream->match(';')) {
            $expressions[] = $this->parseConditional($stream);
        }

        if (count($expressions) === 1) {
            return $expressions[0];
        }

        return [
            'type' => 'sequence',
            'expressions' => $expressions,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseConditional(TokenStream $stream): array
    {
        $expression = $this->parseAssignment($stream);

        if (! $stream->match('?')) {
            return $expression;
        }

        $consequent = $this->parseConditional($stream);

        return [
            'type' => 'conditional',
            'test' => $expression,
            'consequent' => $consequent,
            'alternate' => $stream->match(':') ? $this->parseConditional($stream) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseAssignment(TokenStream $stream): array
    {
        $expression = $this->parseFallback($stream);

        if (! $stream->match('operator', ':=')) {
            return $expression;
        }

        return [
            'type' => 'assignment',
            'target' => $expression,
            'value' => $this->parseConditional($stream),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseFallback(TokenStream $stream): array
    {
        $expression = $this->parseChain($stream);

        while (true) {
            if ($stream->match('operator', '??')) {
                $operator = '??';
            } elseif ($stream->match('operator', '?:')) {
                $operator = '?:';
            } else {
                break;
            }

            $expression = [
                'type' => 'binary',
                'operator' => $operator,
                'left' => $expression,
                'right' => $this->parseChain($stream),
            ];
        }

        return $expression;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseChain(TokenStream $stream): array
    {
        $expression = $this->parseConcatenation($stream);

        while ($stream->match('operator', '~>')) {
            $expression = [
                'type' => 'binary',
                'operator' => '~>',
                'left' => $expression,
                'right' => $this->parseConcatenation($stream),
            ];
        }

        return $expression;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseRange(TokenStream $stream): array
    {
        $expression = $this->parseAdditive($stream);

        while ($stream->match('operator', '..')) {
            $expression = [
                'type' => 'binary',
                'operator' => '..',
                'left' => $expression,
                'right' => $this->parseAdditive($stream),
            ];
        }

        return $expression;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseConcatenation(TokenStream $stream): array
    {
        $expression = $this->parseOr($stream);

        while ($stream->match('operator', '&')) {
            $expression = [
                'type' => 'binary',
                'operator' => '&',
                'left' => $expression,
                'right' => $this->parseOr($stream),
            ];
        }

        return $expression;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseOr(TokenStream $stream): array
    {
        $expression = $this->parseAnd($stream);

        while ($stream->match('operator', 'or')) {
            $expression = [
                'type' => 'binary',
                'operator' => 'or',
                'left' => $expression,
                'right' => $this->parseAnd($stream),
            ];
        }

        return $expression;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseAnd(TokenStream $stream): array
    {
        $expression = $this->parseEquality($stream);

        while ($stream->match('operator', 'and')) {
            $expression = [
                'type' => 'binary',
                'operator' => 'and',
                'left' => $expression,
                'right' => $this->parseEquality($stream),
            ];
        }

        return $expression;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseEquality(TokenStream $stream): array
    {
        $expression = $this->parseComparison($stream);

        while (true) {
            if ($stream->match('operator', '=')) {
                $operator = '=';
            } elseif ($stream->match('operator', '!=')) {
                $operator = '!=';
            } else {
                break;
            }

            $expression = [
                'type' => 'binary',
                'operator' => $operator,
                'left' => $expression,
                'right' => $this->parseComparison($stream),
            ];
        }

        return $expression;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseComparison(TokenStream $stream): array
    {
        $expression = $this->parseRange($stream);

        while (true) {
            $operator = null;

            foreach (['<', '<=', '>', '>=', 'in'] as $candidate) {
                if ($stream->match('operator', $candidate)) {
                    $operator = $candidate;
                    break;
                }
            }

            if ($operator === null) {
                break;
            }

            $expression = [
                'type' => 'binary',
                'operator' => $operator,
                'left' => $expression,
                'right' => $this->parseRange($stream),
            ];
        }

        return $expression;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseAdditive(TokenStream $stream): array
    {
        $expression = $this->parseMultiplicative($stream);

        while (true) {
            if ($stream->match('operator', '+')) {
                $operator = '+';
            } elseif ($stream->match('operator', '-')) {
                $operator = '-';
            } else {
                break;
            }

            $expression = [
                'type' => 'binary',
                'operator' => $operator,
                'left' => $expression,
                'right' => $this->parseMultiplicative($stream),
            ];
        }

        return $expression;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseMultiplicative(TokenStream $stream): array
    {
        $expression = $this->parsePower($stream);

        while (true) {
            if ($stream->match('operator', '*')) {
                $operator = '*';
            } elseif ($stream->match('operator', '/')) {
                $operator = '/';
            } elseif ($stream->match('operator', '%')) {
                $operator = '%';
            } else {
                break;
            }

            $expression = [
                'type' => 'binary',
                'operator' => $operator,
                'left' => $expression,
                'right' => $this->parsePower($stream),
            ];
        }

        return $expression;
    }

    /**
     * @return array<string, mixed>
     */
    private function parsePower(TokenStream $stream): array
    {
        $expression = $this->parseUnary($stream);

        if (! $stream->match('operator', '**')) {
            return $expression;
        }

        return [
            'type' => 'binary',
            'operator' => '**',
            'left' => $expression,
            'right' => $this->parsePower($stream),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseUnary(TokenStream $stream): array
    {
        if ($stream->match('operator', '-')) {
            return [
                'type' => 'unary',
                'operator' => '-',
                'argument' => $this->parseUnary($stream),
            ];
        }

        if ($stream->match('operator', '+')) {
            return [
                'type' => 'unary',
                'operator' => '+',
                'argument' => $this->parseUnary($stream),
            ];
        }

        return $this->parsePostfix($stream);
    }

    /**
     * @return array<string, mixed>
     */
    private function parsePostfix(TokenStream $stream): array
    {
        $expression = $this->parsePrimary($stream);

        while (true) {
            if ($stream->match('operator', '@')) {
                $binding = $stream->expect('variable');
                $expression = [
                    'type' => 'bind',
                    'kind' => 'focus',
                    'target' => $expression,
                    'name' => $binding['value'],
                ];

                continue;
            }

            if ($stream->match('operator', '#')) {
                $binding = $stream->expect('variable');
                $expression = [
                    'type' => 'bind',
                    'kind' => 'index',
                    'target' => $expression,
                    'name' => $binding['value'],
                ];

                continue;
            }

            if ($stream->match('.')) {
                if ($stream->match('operator', '%')) {
                    $expression = [
                        'type' => 'parent',
                        'target' => $expression,
                    ];
                } elseif ($stream->match('operator', '**')) {
                    $expression = [
                        'type' => 'descendant',
                        'target' => $expression,
                    ];
                } elseif ($stream->match('operator', '*')) {
                    $expression = [
                        'type' => 'wildcard',
                        'target' => $expression,
                    ];
                } elseif ($stream->match('operator', '%')) {
                    $expression = [
                        'type' => 'parent',
                        'target' => $expression,
                    ];
                } elseif ($stream->check('{')) {
                    $expression = [
                        'type' => 'object_map',
                        'target' => $expression,
                        'object' => $this->parseObjectLiteral($stream),
                    ];
                } elseif (
                    $stream->check('(')
                    || $stream->check('[')
                    || $stream->check('keyword', 'function')
                ) {
                    $expression = [
                        'type' => 'path_step',
                        'target' => $expression,
                        'step' => $this->parsePostfix($stream),
                    ];
                } else {
                    $property = $stream->expectAny(['identifier', 'variable', 'string']);
                    $expression = [
                        'type' => 'property',
                        'target' => $expression,
                        'name' => $property['value'],
                    ];
                }

                continue;
            }

            if ($stream->match('[')) {
                $indexOrPredicate = $this->parseExpression($stream);
                $stream->expect(']');
                $expression = $this->isSubscriptExpression($indexOrPredicate)
                    ? [
                        'type' => 'subscript',
                        'target' => $expression,
                        'index' => $indexOrPredicate,
                    ]
                    : [
                        'type' => 'filter',
                        'target' => $expression,
                        'predicate' => $indexOrPredicate,
                    ];

                continue;
            }

            if ($stream->match('(')) {
                $arguments = [];

                if (! $stream->check(')')) {
                    do {
                        $arguments[] = $this->parseCallArgument($stream);
                    } while ($stream->match(','));
                }

                $stream->expect(')');
                $expression = [
                    'type' => $this->containsPlaceholder($arguments) ? 'partial' : 'call',
                    'callee' => $expression,
                    'arguments' => $arguments,
                ];

                continue;
            }

            if ($stream->match('operator', '^')) {
                $stream->expect('(');
                $terms = [];

                if (! $stream->check(')')) {
                    do {
                        $descending = false;

                        if ($stream->match('operator', '<')) {
                            $descending = false;
                        } elseif ($stream->match('operator', '>')) {
                            $descending = true;
                        }

                        $terms[] = [
                            'descending' => $descending,
                            'expression' => $this->parseExpression($stream),
                        ];
                    } while ($stream->match(','));
                }

                $stream->expect(')');
                $expression = [
                    'type' => 'sort',
                    'target' => $expression,
                    'terms' => $terms,
                ];

                continue;
            }

            if ($stream->check('{')) {
                $expression = $this->parseGroupExpression($stream, $expression);

                continue;
            }

            break;
        }

        return $expression;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseCallArgument(TokenStream $stream): array
    {
        if ($stream->check('?') && in_array($stream->peek(1)['type'], [',', ')'], true)) {
            $stream->advance();

            return [
                'type' => 'placeholder',
            ];
        }

        return $this->parseExpression($stream);
    }

    /**
     * @param  array<int, array<string, mixed>>  $arguments
     */
    private function containsPlaceholder(array $arguments): bool
    {
        foreach ($arguments as $argument) {
            if (($argument['type'] ?? null) === 'placeholder') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function parsePrimary(TokenStream $stream): array
    {
        $token = $stream->peek();

        return match ($token['type']) {
            'string' => (
                $stream->peek(1)['type'] ?? null
            ) === '.'
                ? [
                    'type' => 'identifier',
                    'name' => $stream->advance()['value'],
                ]
                : [
                    'type' => 'literal',
                    'value' => $stream->advance()['value'],
                ],
            'number', 'boolean', 'null', 'regex' => [
                'type' => 'literal',
                'value' => $stream->advance()['value'],
            ],
            'identifier' => [
                'type' => 'identifier',
                'name' => $stream->advance()['value'],
            ],
            'variable' => [
                'type' => 'variable',
                'name' => $stream->advance()['value'],
            ],
            'keyword' => $token['value'] === 'function'
                ? $this->parseFunctionDefinition($stream)
                : throw new EvaluationException(
                    sprintf('Error S0203: Unexpected keyword [%s].', $token['value']),
                    'S0203',
                    (int) $token['position'],
                    ['position' => (int) $token['position']]
                ),
            '[' => $this->parseArrayLiteral($stream),
            '{' => $this->parseObjectLiteral($stream),
            '(' => $this->parseGroupedExpression($stream),
            'operator' => match ($token['value']) {
                '*', '**' => [
                    'type' => $stream->advance()['value'] === '*' ? 'wildcard_context' : 'descendant_context',
                ],
                '%' => [
                    'type' => 'parent_context',
                    'position' => $stream->advance()['position'],
                ],
                '|' => $this->parseTransformExpression($stream),
                default => throw new EvaluationException(
                    sprintf('Error S0203: Unexpected operator [%s].', (string) $token['value']),
                    'S0203',
                    (int) $token['position'],
                    ['position' => (int) $token['position']]
                ),
            },
            default => throw new EvaluationException(
                sprintf('Error S0203: Unexpected token [%s].', (string) ($token['value'] ?? $token['type'])),
                'S0203',
                (int) $token['position'],
                ['position' => (int) $token['position']]
            ),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function parseArrayLiteral(TokenStream $stream): array
    {
        $stream->expect('[');
        $items = [];

        if (! $stream->check(']')) {
            do {
                $items[] = $this->parseExpression($stream);
            } while ($stream->match(','));
        }

        $stream->expect(']');

        return [
            'type' => 'array',
            'items' => $items,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseObjectLiteral(TokenStream $stream): array
    {
        $stream->expect('{');
        $pairs = [];

        if (! $stream->check('}')) {
            do {
                $keyExpression = $this->parseExpression($stream);
                $stream->expect(':');

                $pairs[] = [
                    'key' => $keyExpression,
                    'value' => $this->parseExpression($stream),
                ];
            } while ($stream->match(','));
        }

        $stream->expect('}');

        return [
            'type' => 'object',
            'pairs' => $pairs,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseGroupedExpression(TokenStream $stream): array
    {
        $stream->expect('(');

        if ($stream->check(')')) {
            $stream->expect(')');

            return [
                'type' => 'grouping',
                'expression' => [
                    'type' => 'sequence',
                    'expressions' => [],
                ],
            ];
        }

        $expression = $this->parseExpression($stream);
        $stream->expect(')');

        return [
            'type' => 'grouping',
            'expression' => $expression,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseFunctionDefinition(TokenStream $stream): array
    {
        $stream->expect('keyword', 'function');
        $stream->expect('(');
        $parameters = [];

        if (! $stream->check(')')) {
            do {
                $parameter = $stream->expect('variable');
                $parameters[] = (string) $parameter['value'];
            } while ($stream->match(','));
        }

        $stream->expect(')');
        $signature = $stream->check('operator', '<', true) ? $this->parseFunctionSignature($stream) : null;
        $stream->expect('{');
        $body = $this->parseExpression($stream);
        $stream->expect('}');

        return [
            'type' => 'function',
            'parameters' => $parameters,
            'signature' => $signature,
            'body' => $body,
        ];
    }

    private function parseFunctionSignature(TokenStream $stream): string
    {
        $signature = '';
        $depth = 0;

        do {
            $token = $stream->advance();
            $value = (string) ($token['value'] ?? $token['type']);
            $signature .= $value;

            if ($token['type'] === 'operator' && $token['value'] === '<') {
                $depth++;
            } elseif ($token['type'] === 'operator' && $token['value'] === '>') {
                $depth--;
            }
        } while ($depth > 0 && ! $stream->check('eof'));

        return $signature;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseTransformExpression(TokenStream $stream): array
    {
        $token = $stream->expect('operator', '|');
        $pattern = $this->parseExpression($stream);
        $stream->expect('operator', '|');
        $update = $this->parseExpression($stream);
        $delete = null;

        if ($stream->match(',')) {
            $delete = $this->parseExpression($stream);
        }

        $stream->expect('operator', '|');

        return [
            'type' => 'transform',
            'position' => (int) $token['position'],
            'pattern' => $pattern,
            'update' => $update,
            'delete' => $delete,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseGroupExpression(TokenStream $stream, array $target): array
    {
        $stream->expect('{');
        $pairs = [];

        if (! $stream->check('}')) {
            do {
                $pairs[] = [
                    'key' => $this->parseExpression($stream),
                    'value' => null,
                ];
                $stream->expect(':');
                $pairs[array_key_last($pairs)]['value'] = $this->parseExpression($stream);
            } while ($stream->match(','));
        }

        $stream->expect('}');

        return [
            'type' => 'group',
            'target' => $target,
            'pairs' => $pairs,
        ];
    }

    /**
     * @param  array<string, mixed>  $expression
     */
    private function isSubscriptExpression(array $expression): bool
    {
        return match ($expression['type'] ?? null) {
            'literal' => is_int($expression['value']) || is_float($expression['value']) || is_string($expression['value']),
            'unary' => ($expression['operator'] ?? null) === '-'
                && ($expression['argument']['type'] ?? null) === 'literal'
                && (is_int($expression['argument']['value'] ?? null) || is_float($expression['argument']['value'] ?? null)),
            'array' => $expression['items'] !== [] && $this->allSelectorItems($expression['items']),
            'binary' => in_array($expression['operator'] ?? null, ['+', '-', '*', '/', '%'], true),
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $expression
     */
    private function isSubscriptSelectorItem(array $expression): bool
    {
        return match ($expression['type'] ?? null) {
            'literal' => is_int($expression['value']) || is_float($expression['value']) || is_bool($expression['value']),
            'unary' => ($expression['operator'] ?? null) === '-'
                && ($expression['argument']['type'] ?? null) === 'literal'
                && (is_int($expression['argument']['value'] ?? null) || is_float($expression['argument']['value'] ?? null)),
            'binary' => ($expression['operator'] ?? null) === '..',
            default => false,
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function allSelectorItems(array $items): bool
    {
        foreach ($items as $item) {
            if (! $this->isSubscriptSelectorItem($item)) {
                return false;
            }
        }

        return true;
    }
}
