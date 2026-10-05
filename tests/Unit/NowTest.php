<?php

use JsonataPhp\EvaluationException;
use JsonataPhp\ExpressionService;

it('formats $now with picture and timezone arguments like JavaScript', function (string $expression, string $pattern) {
    $js = jsonata_test_evaluate_with_local_js($expression, null);

    expect($js['ok'])->toBeTrue();
    expect($js['result'])->toMatch($pattern);
    expect((new ExpressionService)->evaluate($expression, null))->toMatch($pattern);
})->with([
    'no arguments' => ['$now()', '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/'],
    'date picture' => ['$now("[Y0001]-[M01]-[D01]")', '/^\d{4}-\d{2}-\d{2}$/'],
    'picture and timezone' => ['$now("[h]:[m01][P] [z]", "-0500")', '/^\d?\d:\d\d[ap]m GMT-05:00$/'],
    'timezone only picture' => ['$now("[Z]", "+0130")', '/^\+01:30$/'],
    'missing picture' => ['$now(missing)', '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/'],
    'missing timezone' => ['$now("[Y0001]", missing)', '/^\d{4}$/'],
]);

it('rejects a non-string $now picture like JavaScript', function () {
    $js = jsonata_test_evaluate_with_local_js('$now(1)', null);

    expect($js['ok'])->toBeFalse();
    expect($js['error']['code'])->toBe('T0410');
    expect(fn () => (new ExpressionService)->evaluate('$now(1)', null))
        ->toThrow(EvaluationException::class, 'Error T0410');
});
