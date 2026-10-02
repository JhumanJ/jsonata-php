<?php

use JsonataPhp\ExpressionService;

it('preserves append results and matches JavaScript', function (string $expression, mixed $expected) {
    $js = jsonata_test_evaluate_with_local_js($expression, []);

    expect($js['ok'])->toBeTrue();
    expect($js['result'])->toEqual($expected);
    expect((new ExpressionService)->evaluate($expression, []))->toEqual($expected);
})->with([
    'empty arrays' => ['$append([], [])', []],
    'singleton left array' => ['$append([{"comment":"x"}], [])', [['comment' => 'x']]],
    'singleton right array' => ['$append([], [{"comment":"x"}])', [['comment' => 'x']]],
    'scalar left argument' => ['$append("x", [])', ['x']],
    'scalar right argument' => ['$append([], "x")', ['x']],
    'two scalars' => ['$append("x", "y")', ['x', 'y']],
    'multiple elements' => ['$append([1, 2], [3])', [1, 2, 3]],
    'nested arrays' => ['$append([[1]], [[2]])', [[1], [2]]],
    'null left argument' => ['$append(null, [])', [null]],
    'null right argument' => ['$append([], null)', [null]],
    'two null arguments' => ['$append(null, null)', [null, null]],
    'missing left argument preserves array' => ['$append(missing, [1])', [1]],
    'missing right argument preserves array' => ['$append([1], missing)', [1]],
    'missing left argument preserves scalar' => ['$append(missing, "x")', 'x'],
    'missing right argument preserves scalar' => ['$append("x", missing)', 'x'],
    'missing argument preserves empty array' => ['$append(missing, [])', []],
    'missing argument preserves null' => ['$append(missing, null)', null],
    'both arguments missing' => ['$append(missing, missing)', null],
    'assigned singleton comments' => [
        '($comments := $append([{"comment":"x"}], []); {"commentaire": $comments})',
        ['commentaire' => [['comment' => 'x']]],
    ],
    'packed singleton comments' => [
        '($a := function($v){$type($v) = "array" ? $v : [$v]};'
        .' $pack := function(){ $a($map([0], function($i){{"comment":"x"}})) };'
        .' {"commentaire": $append($pack(), [])})',
        ['commentaire' => [['comment' => 'x']]],
    ],
]);
