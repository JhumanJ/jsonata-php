<?php

use JsonataPhp\Evaluator;
use JsonataPhp\ExpressionService;
use JsonataPhp\Formatters\IntegerFormatter;
use JsonataPhp\Formatters\NumberFormatter;
use JsonataPhp\Functions;
use JsonataPhp\Lexer;
use JsonataPhp\Parser;
use Symfony\Component\Process\Process;

function jsonata_test_resolve(string $class): object
{
    return match ($class) {
        ExpressionService::class => new ExpressionService,
        Lexer::class => new Lexer,
        Parser::class => new Parser,
        Evaluator::class => new Evaluator(
            new Functions(
                new Lexer,
                new Parser,
                new IntegerFormatter,
                new NumberFormatter
            )
        ),
        default => new $class,
    };
}

function package_path(string $path = ''): string
{
    $root = dirname(__DIR__);

    if ($path === '' || $path === '.') {
        return $root;
    }

    return $root.'/'.ltrim($path, '/');
}

function jsonata_test_upstream_dir(): ?string
{
    static $resolved = false;
    static $directory = null;

    if ($resolved) {
        return $directory;
    }

    $resolved = true;

    $configured = getenv('JSONATA_UPSTREAM_DIR');
    if (is_string($configured) && $configured !== '' && is_dir($configured.'/test/test-suite')) {
        return $directory = $configured;
    }

    $directory = sys_get_temp_dir().'/jsonata-upstream';

    if (is_dir($directory.'/test/test-suite')) {
        return $directory;
    }

    $process = new Process([
        'git',
        'clone',
        '--depth',
        '1',
        'https://github.com/jsonata-js/jsonata.git',
        $directory,
    ], package_path('.'));

    $process->run();

    return $process->isSuccessful() && is_dir($directory.'/test/test-suite')
        ? $directory
        : null;
}

/**
 * @return array<string, mixed>
 */
function jsonata_test_upstream_datasets(): array
{
    static $datasets = null;

    if ($datasets !== null) {
        return $datasets;
    }

    $upstream = jsonata_test_upstream_dir();
    if ($upstream === null) {
        return $datasets = [];
    }

    $datasets = [];

    foreach (glob($upstream.'/test/test-suite/datasets/*.json') ?: [] as $path) {
        $datasets[basename($path, '.json')] = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    return $datasets;
}

/**
 * @param  list<string>  $groups
 * @param  list<string>  $allowedCases
 * @return array<string, array<string, mixed>>
 */
function jsonata_test_upstream_cases(array $groups, array $allowedCases = []): array
{
    $upstream = jsonata_test_upstream_dir();
    if ($upstream === null) {
        return [];
    }

    $fixtures = [];

    foreach ($groups as $group) {
        $directory = $upstream.'/test/test-suite/groups/'.$group;

        foreach (glob($directory.'/*.json') ?: [] as $path) {
            $decoded = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            $cases = array_is_list($decoded) ? $decoded : [$decoded];

            foreach ($cases as $index => $case) {
                $caseId = $group.'/'.basename($path).(array_is_list($decoded) ? '#'.$index : '');

                if ($allowedCases !== [] && ! in_array($caseId, $allowedCases, true)) {
                    continue;
                }

                if (isset($case['expr-file'])) {
                    $case['expr'] = file_get_contents($directory.'/'.$case['expr-file']);
                }

                $case['_case_id'] = $caseId;
                $case['_group'] = $group;

                $fixtures[$caseId] = $case;
            }
        }
    }

    ksort($fixtures);

    return $fixtures;
}

/**
 * @return array{ok: bool, result?: mixed, error?: array<string, mixed>}
 */
function jsonata_test_evaluate_with_local_js(string $expression, mixed $context, array $bindings = [], ?string $jsonataPath = null): array
{
    $script = <<<'JS'
async function main() {
  const jsonataPath = process.argv[1];
  const expression = process.argv[2];
  const context = JSON.parse(process.argv[3]);
  const bindings = JSON.parse(process.argv[4]);
  const jsonata = require(jsonataPath);

  try {
    const compiled = jsonata(expression);
    try {
      const result = await compiled.evaluate(context, bindings);
      process.stdout.write(JSON.stringify({
        ok: true,
        result: typeof result === 'undefined' ? null : result,
        undefinedResult: typeof result === 'undefined'
      }));
    } catch (error) {
      process.stdout.write(JSON.stringify({
        ok: false,
        error: {
          code: error && error.code ? error.code : null,
          token: error && error.token ? error.token : null,
          position: error && typeof error.position !== 'undefined' ? error.position : null,
          message: error && error.message ? error.message : String(error)
        }
      }));
    }
  } catch (error) {
    process.stdout.write(JSON.stringify({
      ok: false,
      error: {
        code: error && error.code ? error.code : null,
        token: error && error.token ? error.token : null,
        position: error && typeof error.position !== 'undefined' ? error.position : null,
        message: error && error.message ? error.message : String(error)
      }
    }));
  }
}

main().catch((error) => {
  process.stderr.write(String(error && error.stack ? error.stack : error));
  process.exit(1);
});
JS;

    $process = new Process([
        'node',
        '-e',
        $script,
        $jsonataPath ?? package_path('node_modules/jsonata/jsonata.js'),
        $expression,
        json_encode($context, JSON_THROW_ON_ERROR),
        json_encode($bindings, JSON_THROW_ON_ERROR),
    ], package_path('.'));

    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
}
