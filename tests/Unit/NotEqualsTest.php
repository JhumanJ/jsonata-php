<?php

use JsonataPhp\ExpressionService;

it('returns false for != with an undefined operand and matches JavaScript', function (string $expression, mixed $context, mixed $expected) {
    $js = jsonata_test_evaluate_with_local_js($expression, $context);

    expect($js['ok'])->toBeTrue();
    expect($js['result'])->toEqual($expected);
    expect((new ExpressionService)->evaluate($expression, $context))->toEqual($expected);
})->with([
    'missing left operand' => ['missing != 1', [], false],
    'missing right operand' => ['1 != missing', [], false],
    'both operands missing' => ['missing != missing', [], false],
    'missing compared with null' => ['o.missing != null', ['o' => ['a' => 1]], false],
    'null compared with missing' => ['null != missing', [], false],
    'unbound variable' => ['$x != "a"', [], false],
    'explicit null is still compared' => ['o.a != null', ['o' => ['a' => null]], false],
    'defined operands still differ' => ['o.a != 1', ['o' => ['a' => 2]], true],
    'path step over items with a missing field' => [
        'items.(x != 1)',
        ['items' => [['x' => 1], ['x' => 2], ['y' => 3]]],
        [false, true, false],
    ],
    'predicate over items with a missing field' => [
        'items[x != 1]',
        ['items' => [['x' => 1], ['x' => 2], ['y' => 3]]],
        ['x' => 2],
    ],
    'lambda body' => [
        '$map(items, function($v) { $v.x != 1 })',
        ['items' => [['x' => 1], ['x' => 2], ['y' => 3]]],
        [false, true, false],
    ],
]);
