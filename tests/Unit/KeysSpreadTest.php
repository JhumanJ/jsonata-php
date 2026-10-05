<?php

use JsonataPhp\ExpressionService;

it('returns JSONata sequences from $keys and $spread and matches JavaScript', function (string $expression, mixed $expected) {
    $js = jsonata_test_evaluate_with_local_js($expression, null);

    expect($js['ok'])->toBeTrue();
    expect($js['result'])->toEqual($expected);
    expect((new ExpressionService)->evaluate($expression, null))->toEqual($expected);
})->with([
    '$keys of an empty object with $exists' => ['$exists($keys({}))', false],
    '$keys of an empty object with $count' => ['$count($keys({}))', 0],
    '$keys of an object with only undefined values' => ['$exists($keys({"n": nothing}))', false],
    '$keys of a string' => ['$exists($keys("foo"))', false],
    '$keys of an array of strings' => ['$exists($keys(["foo", "bar"]))', false],
    '$keys of an empty object in an array constructor' => ['[$keys({}), "x"]', ['x']],
    '$keys of a single-key object' => ['$type($keys({"a": 1}))', 'string'],
    '$keys of an object' => ['$keys({"a": 1, "b": 2})', ['a', 'b']],
    '$keys of an array of objects' => ['$keys([{"a": 1}, {"b": 2, "a": 3}])', ['a', 'b']],
    '$spread of an empty array' => ['$exists($spread([]))', false],
    '$spread of an empty object' => ['$exists($spread({}))', false],
    '$spread of an empty object as a field value' => ['{"s": $spread({}), "t": 1}', ['t' => 1]],
    '$spread of a single-key object' => ['$spread({"a": 1})', ['a' => 1]],
    '$spread of a single-key object with $type' => ['$type($spread({"a": 1}))', 'object'],
    '$spread of an object' => ['$spread({"a": 1, "b": 2})', [['a' => 1], ['b' => 2]]],
    '$spread of an array keeps a singleton array' => ['$spread([{"a": 1}])', [['a' => 1]]],
    '$spread of an array of objects' => ['$spread([{"a": 1}, {"b": 2}])', [['a' => 1], ['b' => 2]]],
    '$spread of a string' => ['$spread("x")', 'x'],
]);
