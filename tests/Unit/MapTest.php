<?php

use JsonataPhp\ExpressionService;

it('returns undefined from $map over an empty array and matches JavaScript', function (string $expression, mixed $context, mixed $expected) {
    $js = jsonata_test_evaluate_with_local_js($expression, $context);

    expect($js['ok'])->toBeTrue();
    expect($js['result'])->toEqual($expected);
    expect((new ExpressionService)->evaluate($expression, $context))->toEqual($expected);
})->with([
    'inside an array constructor' => ['[$map([], function($v) { $v })]', null, []],
    'next to other array items' => ['[$map(items, function($v) { $v.y }), 5]', ['items' => []], [5]],
    'object constructor callback' => ['[$map(items, function($v) { {"x": $v.y} })]', ['items' => []], []],
    'counted in an array constructor' => ['$count([$map(items, function($v) { $v })])', ['items' => []], 0],
    'as an object field value' => ['{"a": $map(items, function($v) { $v }), "b": 2}', ['items' => []], ['b' => 2]],
    'with $exists' => ['$exists($map([], function($v) { $v }))', null, false],
    'with the coalescing operator' => ['$map([], function($v) { $v }) ?? "none"', null, 'none'],
    'null input is still mapped' => ['[$map(null, function($v) { 1 })]', null, [1]],
    'non-empty input' => ['[$map(items, function($v) { $v.y })]', ['items' => [['y' => 1]]], [1]],
]);
