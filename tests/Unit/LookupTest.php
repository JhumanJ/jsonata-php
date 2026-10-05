<?php

use JsonataPhp\ExpressionService;

it('returns undefined from $lookup for an absent key and matches JavaScript', function (string $expression, mixed $context, mixed $expected) {
    $js = jsonata_test_evaluate_with_local_js($expression, $context);

    expect($js['ok'])->toBeTrue();
    expect($js['result'])->toEqual($expected);
    expect((new ExpressionService)->evaluate($expression, $context))->toEqual($expected);
})->with([
    'absent key with $exists' => ['$exists($lookup({"a": 1}, "k"))', null, false],
    'absent key with the coalescing operator' => ['$lookup({"a": 1}, "k") ?? "fallback"', null, 'fallback'],
    'absent key in an array constructor' => ['[$lookup({"a": 1}, "k"), 2]', null, [2]],
    'absent key counted' => ['$count($lookup({"a": 1}, "k"))', null, 0],
    'absent key as an object field value' => ['{"d": $lookup(o, "k"), "e": 1}', ['o' => ['a' => 1]], ['e' => 1]],
    'absent key in every array item' => ['{"d": $lookup([{"b": 1}], "a"), "e": 1}', null, ['e' => 1]],
    'empty array input' => ['{"d": $lookup([], "a"), "e": 1}', null, ['e' => 1]],
    'non-object input' => ['{"d": $lookup("str", "a"), "e": 1}', null, ['e' => 1]],
    'explicit null value is kept' => ['{"d": $lookup({"a": null}, "a")}', null, ['d' => null]],
    'explicit null values in array items are kept' => ['$lookup([{"a": null}, {"a": 1}], "a")', null, [null, 1]],
    'present key' => ['$lookup([{"a": 1}, {"b": 2}, {"a": 3}], "a")', null, [1, 3]],
]);
