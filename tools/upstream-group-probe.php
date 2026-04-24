<?php

use JsonataPhp\EvaluationException;
use JsonataPhp\ExpressionService;

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/../tests/Pest.php';

function normalizeProbeValue(mixed $value, bool $unordered = false): mixed
{
    if (! is_array($value)) {
        return $value;
    }

    $normalized = array_map(
        fn (mixed $item): mixed => normalizeProbeValue($item, $unordered),
        $value
    );

    if ($unordered && array_is_list($normalized)) {
        usort($normalized, fn (mixed $left, mixed $right): int => strcmp(json_encode($left), json_encode($right)));
    }

    return $normalized;
}

function probeInput(array $case): mixed
{
    if (array_key_exists('data', $case)) {
        return $case['data'];
    }

    $dataset = $case['dataset'] ?? null;

    if ($dataset === null) {
        return null;
    }

    return jsonata_test_upstream_datasets()[$dataset] ?? null;
}

function probeCase(ExpressionService $service, array $case): array
{
    if (isset($case['_json_decode_error'])) {
        return [false, 'json decode: '.$case['_json_decode_error']];
    }

    $input = probeInput($case);
    $bindings = $case['bindings'] ?? [];

    try {
        $js = jsonata_test_evaluate_with_local_js($case['expr'], $input, $bindings);
    } catch (Throwable $exception) {
        return [false, 'js probe failed: '.$exception->getMessage()];
    }

    try {
        $php = [
            'ok' => true,
            'result' => $service->evaluate($case['expr'], $input, $bindings),
        ];
    } catch (EvaluationException $exception) {
        $php = [
            'ok' => false,
            'error' => ['code' => $exception->jsonataCode],
        ];
    } catch (Throwable $exception) {
        $php = [
            'ok' => false,
            'error' => ['code' => get_class($exception), 'message' => $exception->getMessage()],
        ];
    }

    if ($php['ok'] !== $js['ok']) {
        return [false, 'status php='.json_encode($php).' js='.json_encode($js).' expr='.$case['expr']];
    }

    if (! $js['ok']) {
        if (($php['error']['code'] ?? null) !== ($js['error']['code'] ?? null)) {
            return [false, 'error code php='.json_encode($php).' js='.json_encode($js).' expr='.$case['expr']];
        }

        return [true, null];
    }

    $unordered = (bool) ($case['unordered'] ?? false);
    $phpResult = normalizeProbeValue($php['result'] ?? null, $unordered);
    $jsResult = normalizeProbeValue($js['result'] ?? null, $unordered);

    if ($phpResult != $jsResult) {
        return [false, 'result php='.json_encode($phpResult).' js='.json_encode($jsResult).' expr='.$case['expr']];
    }

    return [true, null];
}

$groups = $argv;
array_shift($groups);

if ($groups === []) {
    $upstream = jsonata_test_upstream_dir();
    $groups = array_map('basename', glob($upstream.'/test-suite/groups/*', GLOB_ONLYDIR) ?: []);
    sort($groups);
}

$service = new ExpressionService;

foreach ($groups as $group) {
    $cases = jsonata_test_upstream_cases([$group]);
    $passed = 0;
    $failed = [];

    foreach ($cases as $case) {
        [$ok, $message] = probeCase($service, $case);
        if ($ok) {
            $passed++;

            continue;
        }

        $failed[] = [$case['_case_id'], $message];
    }

    printf("%s: %d passed, %d failed\n", $group, $passed, count($failed));

    foreach (array_slice($failed, 0, 5) as [$caseId, $message]) {
        printf("  - %s: %s\n", $caseId, $message);
    }
}
