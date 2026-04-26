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
    if (is_string($configured) && $configured !== '' && is_dir($configured.'/test-suite')) {
        return $directory = $configured;
    }

    $directory = package_path('tests/fixtures/upstream-jsonata');

    if (is_dir($directory.'/test-suite')) {
        return $directory;
    }

    return null;
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

    foreach (glob($upstream.'/test-suite/datasets/*.json') ?: [] as $path) {
        $datasets[basename($path, '.json')] = jsonata_test_decode_upstream_json_file($path);
    }

    return $datasets;
}

function jsonata_test_decode_upstream_json_file(string $path): mixed
{
    $contents = file_get_contents($path);

    try {
        return json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        if (! str_contains($exception->getMessage(), 'Single unpaired UTF-16 surrogate')) {
            throw $exception;
        }
    }

    $surrogates = [];
    $placeholderBase = 0xE000;
    $sanitized = preg_replace_callback(
        '/\\\\u([0-9a-fA-F]{4})/',
        static function (array $match) use ($contents, &$surrogates, $placeholderBase): string {
            $codeUnit = hexdec($match[1][0]);

            if ($codeUnit < 0xD800 || $codeUnit > 0xDFFF) {
                return $match[0][0];
            }

            $offset = $match[0][1];
            $paired = false;

            if ($codeUnit >= 0xD800 && $codeUnit <= 0xDBFF) {
                $nextEscape = substr($contents, $offset + 6, 6);
                $paired = preg_match('/^\\\\u[dD][c-fC-F][0-9a-fA-F]{2}$/', $nextEscape) === 1;
            } else {
                $previousEscape = $offset >= 6 ? substr($contents, $offset - 6, 6) : '';
                $paired = preg_match('/^\\\\u[dD][89a-bA-B][0-9a-fA-F]{2}$/', $previousEscape) === 1;
            }

            if ($paired) {
                return $match[0][0];
            }

            $placeholder = sprintf('\\u%04X', $placeholderBase + count($surrogates));
            $surrogates[$placeholder] = jsonata_test_utf16_surrogate_to_invalid_utf8($codeUnit);

            return $placeholder;
        },
        $contents,
        -1,
        $count,
        PREG_OFFSET_CAPTURE
    );

    $decoded = json_decode($sanitized ?? $contents, true, 512, JSON_THROW_ON_ERROR);

    if ($surrogates === []) {
        return $decoded;
    }

    $replacements = [];
    foreach (array_values($surrogates) as $index => $value) {
        $replacements[mb_chr($placeholderBase + $index, 'UTF-8')] = $value;
    }

    return jsonata_test_replace_strings_recursive($decoded, $replacements);
}

function jsonata_test_utf16_surrogate_to_invalid_utf8(int $codeUnit): string
{
    return chr(0xE0 | ($codeUnit >> 12))
        .chr(0x80 | (($codeUnit >> 6) & 0x3F))
        .chr(0x80 | ($codeUnit & 0x3F));
}

/**
 * @param  array<string, string>  $replacements
 */
function jsonata_test_replace_strings_recursive(mixed $value, array $replacements): mixed
{
    if (is_string($value)) {
        return strtr($value, $replacements);
    }

    if (! is_array($value)) {
        return $value;
    }

    foreach ($value as $key => $item) {
        $value[$key] = jsonata_test_replace_strings_recursive($item, $replacements);
    }

    return $value;
}

function jsonata_test_expression_for_local_js(string $expression): string
{
    return preg_replace_callback(
        '/\xED[\xA0-\xBF][\x80-\xBF]/',
        static function (array $match): string {
            $bytes = array_map('ord', str_split($match[0]));
            $codeUnit = (($bytes[0] & 0x0F) << 12) | (($bytes[1] & 0x3F) << 6) | ($bytes[2] & 0x3F);

            return sprintf('\\u%04X', $codeUnit);
        },
        $expression
    ) ?? $expression;
}

/**
 * @param  list<string>|null  $groups
 * @param  list<string>  $allowedCases
 * @return array<string, array<string, mixed>>
 */
function jsonata_test_upstream_cases(?array $groups = null, array $allowedCases = []): array
{
    $upstream = jsonata_test_upstream_dir();
    if ($upstream === null) {
        return [];
    }

    $fixtures = [];
    $groups ??= array_map(
        'basename',
        glob($upstream.'/test-suite/groups/*', GLOB_ONLYDIR) ?: []
    );
    sort($groups);

    foreach ($groups as $group) {
        $directory = $upstream.'/test-suite/groups/'.$group;

        foreach (glob($directory.'/*.json') ?: [] as $path) {
            $caseId = $group.'/'.basename($path);

            try {
                $decoded = jsonata_test_decode_upstream_json_file($path);
            } catch (JsonException $exception) {
                if ($allowedCases === [] || in_array($caseId, $allowedCases, true)) {
                    $fixtures[$caseId] = [
                        '_case_id' => $caseId,
                        '_group' => $group,
                        '_json_decode_error' => $exception->getMessage(),
                    ];
                }

                continue;
            }

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
function jsonata_test_expected_upstream_outcome(array $case): ?array
{
    if (array_key_exists('code', $case)) {
        return [
            'ok' => false,
            'error' => ['code' => $case['code']],
        ];
    }

    if (array_key_exists('result', $case)) {
        return [
            'ok' => true,
            'result' => $case['result'],
        ];
    }

    if (($case['undefinedResult'] ?? false) === true) {
        return [
            'ok' => true,
            'result' => null,
            'undefinedResult' => true,
        ];
    }

    return null;
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
  const emit = (payload) => process.stdout.write(JSON.stringify(payload), () => process.exit(0));

  try {
    const compiled = jsonata(expression);
    try {
      const result = await compiled.evaluate(context, bindings);
      emit({
        ok: true,
        result: typeof result === 'undefined' ? null : result,
        undefinedResult: typeof result === 'undefined'
      });
    } catch (error) {
      emit({
        ok: false,
        error: {
          code: error && error.code ? error.code : null,
          token: error && error.token ? error.token : null,
          position: error && typeof error.position !== 'undefined' ? error.position : null,
          message: error && error.message ? error.message : String(error)
        }
      });
    }
  } catch (error) {
    emit({
      ok: false,
      error: {
        code: error && error.code ? error.code : null,
        token: error && error.token ? error.token : null,
        position: error && typeof error.position !== 'undefined' ? error.position : null,
        message: error && error.message ? error.message : String(error)
      }
    });
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
        jsonata_test_expression_for_local_js($expression),
        json_encode($context, JSON_THROW_ON_ERROR),
        json_encode($bindings, JSON_THROW_ON_ERROR),
    ], package_path('.'));
    $process->setTimeout(10);

    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
}
