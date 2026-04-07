<?php

use JsonataPhp\EvaluationException;
use JsonataPhp\ExpressionService;

function jsonata_upstream_theme_cases(string $theme): array
{
    $themes = [
        'functions' => [
            'groups' => [
                'function-append',
                'function-contains',
                'function-join',
                'function-keys',
                'function-length',
                'function-lookup',
                'function-lowercase',
                'function-pad',
                'function-replace',
                'function-split',
                'function-substring',
                'function-substringAfter',
                'function-substringBefore',
                'function-trim',
                'function-uppercase',
            ],
            'cases' => [
                'function-append/case000.json',
                'function-append/case001.json',
                'function-append/case002.json',
                'function-contains/case000.json',
                'function-contains/case001.json',
                'function-join/case000.json',
                'function-join/case001.json',
                'function-join/case002.json',
                'function-keys/case000.json',
                'function-length/case000.json',
                'function-length/case006.json',
                'function-length/case010.json',
                'function-lookup/case000.json',
                'function-lookup/case001.json',
                'function-lowercase/case000.json',
                'function-pad/case010.json',
                'function-pad/case011.json',
                'function-replace/case000.json',
                'function-replace/case001.json',
                'function-replace/case002.json',
                'function-split/case000.json',
                'function-split/case001.json',
                'function-split/case002.json',
                'function-substring/case000.json',
                'function-substring/case006.json',
                'function-substring/case010.json',
                'function-substring/case011.json',
                'function-substringAfter/case000.json',
                'function-substringAfter/case001.json',
                'function-substringBefore/case000.json',
                'function-substringBefore/case001.json',
                'function-trim/case000.json',
                'function-trim/case001.json',
                'function-uppercase/case000.json',
            ],
        ],
        'datetime' => [
            'groups' => [
                'function-formatInteger',
                'function-parseInteger',
                'function-tomillis',
                'function-fromMillis',
            ],
            'cases' => [
                'function-formatInteger/formatInteger.json#1',
                'function-formatInteger/formatInteger.json#2',
                'function-formatInteger/formatInteger.json#3',
                'function-parseInteger/parseInteger.json#1',
                'function-parseInteger/parseInteger.json#2',
                'function-tomillis/case001.json',
                'function-tomillis/case002.json',
                'function-tomillis/case003.json',
                'function-fromMillis/case000.json',
                'function-fromMillis/case001.json',
            ],
        ],
        'higher-order' => [
            'groups' => [
                'higher-order-functions',
                'hof-filter',
                'hof-map',
                'hof-reduce',
                'hof-single',
                'lambdas',
                'partial-application',
            ],
            'cases' => [
                'higher-order-functions/case000.json',
                'higher-order-functions/case001.json',
                'hof-filter/case000.json',
                'hof-map/case000.json',
                'hof-map/case001.json',
                'hof-reduce/case002.json',
                'hof-reduce/case005.json',
                'hof-single/case000.json',
                'lambdas/case000.json',
                'lambdas/case001.json',
                'lambdas/case002.json',
                'partial-application/case000.json',
                'partial-application/case001.json',
            ],
        ],
        'signatures' => [
            'groups' => ['function-signatures'],
            'cases' => [
                'function-signatures/case000.json',
                'function-signatures/case002.json',
                'function-signatures/case003.json',
                'function-signatures/case004.json',
                'function-signatures/case005.json',
                'function-signatures/case006.json',
                'function-signatures/case007.json',
                'function-signatures/case008.json',
                'function-signatures/case009.json',
                'function-signatures/case010.json',
                'function-signatures/case011.json',
                'function-signatures/case012.json',
                'function-signatures/case013.json',
                'function-signatures/case014.json',
                'function-signatures/case015.json',
                'function-signatures/case016.json',
                'function-signatures/case017.json',
                'function-signatures/case018.json',
                'function-signatures/case019.json',
                'function-signatures/case020.json',
                'function-signatures/case021.json',
                'function-signatures/case022.json',
                'function-signatures/case023.json',
                'function-signatures/case024.json',
                'function-signatures/case025.json',
                'function-signatures/case026.json',
                'function-signatures/case027.json',
                'function-signatures/case028.json',
                'function-signatures/case029.json',
                'function-signatures/case030.json',
                'function-signatures/case031.json',
                'function-signatures/case032.json',
                'function-signatures/case033.json',
                'function-signatures/case039.json',
                'function-signatures/case040.json',
            ],
        ],
        'paths' => [
            'groups' => [
                'descendent-operator',
                'missing-paths',
                'multiple-array-selectors',
                'parent-operator',
                'predicates',
                'quoted-selectors',
                'simple-array-selectors',
                'wildcards',
            ],
            'cases' => [
                'descendent-operator/case000.json',
                'descendent-operator/case001.json',
                'descendent-operator/case002.json',
                'descendent-operator/case003.json',
                'descendent-operator/case004.json',
                'descendent-operator/case005.json',
                'descendent-operator/case006.json',
                'descendent-operator/case007.json',
                'descendent-operator/case008.json',
                'descendent-operator/case009.json',
                'descendent-operator/case010.json',
                'descendent-operator/case011.json',
                'descendent-operator/case012.json',
                'descendent-operator/case013.json',
                'descendent-operator/case014.json',
                'descendent-operator/case015.json',
                'descendent-operator/case016.json',
                'missing-paths/case000.json',
                'missing-paths/case001.json',
                'multiple-array-selectors/case000.json',
                'multiple-array-selectors/case001.json',
                'multiple-array-selectors/case002.json',
                'parent-operator/parent.json#0',
                'parent-operator/parent.json#1',
                'parent-operator/parent.json#2',
                'parent-operator/parent.json#3',
                'parent-operator/parent.json#4',
                'parent-operator/parent.json#5',
                'parent-operator/parent.json#6',
                'parent-operator/parent.json#7',
                'parent-operator/parent.json#8',
                'parent-operator/parent.json#9',
                'parent-operator/parent.json#10',
                'parent-operator/parent.json#11',
                'parent-operator/parent.json#12',
                'parent-operator/parent.json#13',
                'parent-operator/parent.json#14',
                'parent-operator/parent.json#15',
                'parent-operator/parent.json#16',
                'parent-operator/parent.json#17',
                'parent-operator/parent.json#18',
                'parent-operator/parent.json#19',
                'parent-operator/parent.json#20',
                'parent-operator/parent.json#21',
                'parent-operator/parent.json#22',
                'parent-operator/parent.json#23',
                'parent-operator/parent.json#24',
                'parent-operator/parent.json#25',
                'parent-operator/parent.json#26',
                'parent-operator/parent.json#27',
                'predicates/case001.json',
                'quoted-selectors/case000.json',
                'quoted-selectors/case001.json',
                'simple-array-selectors/case000.json',
                'simple-array-selectors/case001.json',
                'simple-array-selectors/case002.json',
                'simple-array-selectors/case003.json',
                'simple-array-selectors/case004.json',
                'simple-array-selectors/case005.json',
                'simple-array-selectors/case007.json',
                'simple-array-selectors/case008.json',
                'simple-array-selectors/case010.json',
                'simple-array-selectors/case011.json',
                'simple-array-selectors/case016.json',
                'wildcards/case001.json',
                'wildcards/case002.json',
            ],
        ],
        'regex' => [
            'groups' => ['regex'],
            'cases' => [
                'regex/case006.json',
                'regex/case010.json',
                'regex/case030.json',
            ],
        ],
        'eval' => [
            'groups' => ['function-eval'],
            'cases' => [
                'function-eval/case000.json',
                'function-eval/case001.json',
                'function-eval/case002.json',
                'function-eval/case003.json',
                'function-eval/case006.json',
                'function-eval/case008.json#0',
                'function-eval/case008.json#1',
                'function-eval/case008.json#2',
                'function-eval/case008.json#3',
            ],
        ],
        'transforms' => [
            'groups' => ['transforms'],
            'cases' => [
                'transforms/case006.json',
                'transforms/case011.json',
            ],
        ],
        'errors' => [
            'groups' => ['function-contains', 'function-replace', 'function-split'],
            'cases' => [
                'function-contains/case006.json',
                'function-replace/case009.json',
                'function-split/case011.json',
            ],
        ],
    ];

    $config = $themes[$theme];

    return array_map(
        fn (array $case): array => [$case],
        jsonata_test_upstream_cases($config['groups'], $config['cases'])
    );
}

function jsonata_upstream_input(array $case): mixed
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

function jsonata_upstream_normalize(mixed $value, bool $unordered = false): mixed
{
    if (! is_array($value)) {
        return $value;
    }

    $normalized = array_map(
        fn (mixed $item): mixed => jsonata_upstream_normalize($item, $unordered),
        $value
    );

    if ($unordered && array_is_list($normalized)) {
        usort($normalized, fn (mixed $left, mixed $right): int => strcmp(json_encode($left), json_encode($right)));
    }

    return $normalized;
}

function jsonata_upstream_assert_case(ExpressionService $service, array $case): void
{
    $input = jsonata_upstream_input($case);
    $bindings = $case['bindings'] ?? [];
    $upstream = jsonata_test_upstream_dir();

    expect($upstream)->not->toBeNull();

    $js = jsonata_test_evaluate_with_local_js(
        $case['expr'],
        $input,
        $bindings,
        $upstream.'/src/jsonata'
    );

    try {
        $php = [
            'ok' => true,
            'result' => $service->evaluate($case['expr'], $input, $bindings),
        ];
    } catch (EvaluationException $exception) {
        $php = [
            'ok' => false,
            'error' => [
                'code' => $exception->jsonataCode,
                'position' => $exception->position,
                'message' => $exception->getMessage(),
            ],
        ];
    }

    expect($php['ok'])->toBe($js['ok'], $case['_case_id'].' :: '.$case['expr']);

    if (! $js['ok']) {
        expect($php['error']['code'] ?? null)->toBe($js['error']['code'] ?? null, $case['_case_id']);

        return;
    }

    $unordered = (bool) ($case['unordered'] ?? false);

    expect(jsonata_upstream_normalize($php['result'] ?? null, $unordered))
        ->toEqual(jsonata_upstream_normalize($js['result'] ?? null, $unordered), $case['_case_id']);
}

describe('Upstream Jsonata parity fixtures', function () {
    beforeEach(function () {
        $this->service = jsonata_test_resolve(ExpressionService::class);
    });

    foreach (['functions', 'datetime', 'higher-order', 'signatures', 'paths', 'regex', 'eval', 'transforms', 'errors'] as $theme) {
        it('matches upstream '.$theme.' fixtures', function (array $case) {
            if (jsonata_test_upstream_dir() === null) {
                $this->markTestSkipped('Unable to clone or locate the upstream jsonata test suite.');
            }

            jsonata_upstream_assert_case($this->service, $case);
        })->with(fn (): array => jsonata_upstream_theme_cases($theme));
    }
});
