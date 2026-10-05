<?php

use JsonataPhp\ExpressionService;

function jsonata_test_wait_for_next_millisecond(): void
{
    $start = (int) floor(microtime(true) * 1000);

    while ((int) floor(microtime(true) * 1000) <= $start) {
        usleep(100);
    }
}

it('returns the same $now and $millis value within one evaluation like JavaScript', function (string $expression) {
    $js = jsonata_test_evaluate_with_local_js(str_replace('$tick()', '$sum([1..10000])', $expression), null);

    expect($js['ok'])->toBeTrue();
    expect($js['result'])->toBeTrue();

    $tick = function (): bool {
        jsonata_test_wait_for_next_millisecond();

        return true;
    };

    expect((new ExpressionService)->evaluate($expression, null, ['tick' => $tick]))->toBeTrue();
})->with([
    '$now' => ['($a := $now(); $tick(); $a = $now())'],
    '$millis' => ['($a := $millis(); $tick(); $a = $millis())'],
    '$now matches $millis' => ['($a := $millis(); $tick(); $fromMillis($a) = $now())'],
]);

it('returns a new timestamp for each evaluation', function () {
    $service = new ExpressionService;

    $first = $service->evaluate('$millis()', null);
    jsonata_test_wait_for_next_millisecond();
    $second = $service->evaluate('$millis()', null);

    expect($second)->toBeGreaterThan($first);
});
