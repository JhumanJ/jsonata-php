# jsonata-php

`jsonata-php` is a standalone PHP implementation of [JSONata](https://jsonata.org/), aligned against the official [jsonata-js/jsonata](https://github.com/jsonata-js/jsonata) test-suite.

The package started as the expression engine used inside the Raydocs workflow runtime. It is now maintained as a public package with explicit compatibility checks against the upstream JavaScript implementation.

## Status

The PHP engine currently passes the full vendored upstream JSONata JS fixture suite used by this repository:

- `1666` upstream fixture cases passing
- `0` skipped upstream fixture cases
- parity checked against the local `jsonata` npm package where the JavaScript runtime can evaluate the fixture safely
- fixture-expected fallback for pathological upstream tail-recursion cases where the JavaScript runtime can crash or hang during local comparison

This means `jsonata-php` is compatible with the official upstream test-suite corpus vendored in this repository. It is not a line-by-line port of the JavaScript source code, and it should not be read as a guarantee that every possible untested behavior is identical. The compatibility claim is deliberately test-suite based.

Implemented language/runtime coverage includes:

- lexer, parser and evaluator coverage for the upstream expression fixtures
- path traversal, selectors, tuple bindings, parent/focus/index operators, wildcards and descendants
- object and array construction, grouping, sorting, transforms and range handling
- lambdas, closures, higher-order functions, partial application and tail-recursion behavior
- string, collection, object, numeric, regex, encoding, datetime and formatting builtins
- JSONata-style error codes for the upstream error fixtures

## Compatibility Matrix

| Area | Status | Notes |
| --- | --- | --- |
| Parser and core expressions | Upstream fixtures passing | Covers literals, operators, grouping, blocks, conditionals, defaults, coalescing, comments and token conversion fixtures. |
| Paths and selectors | Upstream fixtures passing | Covers `@`, `#`, `%`, `*`, `**`, projections, filters, quoted selectors, missing paths and tuple-aware traversal. |
| Standard library functions | Upstream fixtures passing | Covers the vendored upstream builtin fixtures, including string, numeric, collection, object, encoding, regex, eval and datetime helpers. |
| Signatures and coercions | Upstream fixtures passing | Covers upstream function signature fixtures and signature-driven coercion/error behavior present in the corpus. |
| Regex | Upstream fixtures passing | Covers regex literals, matching, splitting, replacing, `$match()` and matcher functions. |
| Datetime and formatting | Upstream fixtures passing | Covers `$toMillis()`, `$fromMillis()`, integer formatting/parsing and number formatting fixtures. |
| Higher-order functions and closures | Upstream fixtures passing | Covers lambdas, closures, HOF helpers, partial application and tail-recursion fixtures. |
| Transforms | Upstream fixtures passing | Covers both `transform` and `transforms` upstream groups, including nested update/delete behavior. |
| Error model | Upstream fixtures passing | Covers upstream error-code parity for the official error fixtures; exact message wording may still differ outside the asserted code paths. |

## Upstream Fixture Parity

The repository includes a structured upstream-fixture parity layer in `tests/Unit/UpstreamParityTest.php`. The fixture corpus is adapted from the official JSONata JavaScript project and vendored under `tests/fixtures/upstream-jsonata` from `jsonata-js/jsonata` commit `597e5ee6ada3e13eaa4880f00468dcc1cba21142`.

Vendored upstream fixture paths:

- `test/test-suite/datasets`
- `test/test-suite/groups`

The parity test enumerates the full upstream fixture catalog. Each fixture is executed through the PHP evaluator and compared with the local `jsonata` npm package. For upstream cases where the local JavaScript comparison process itself is not reliable, such as non-terminating tail-recursion fixtures, the test uses the expected result or error code recorded in the official fixture.

Run the upstream compatibility suite with:

```bash
vendor/bin/pest tests/Unit/UpstreamParityTest.php --filter='Upstream Jsonata parity fixtures'
```

The expected result is zero skipped upstream fixture cases.

## Installation

```bash
composer require jhumanj/jsonata-php
```

## Usage

```php
<?php

use JsonataPhp\ExpressionService;

$jsonata = new ExpressionService();

$result = $jsonata->evaluate(
    '$map(input.value[document_type = "invoice"], function($segment) { $segment.document.id })',
    [
        'input' => [
            'value' => [
                ['document_type' => 'invoice', 'document' => ['id' => 'doc_1']],
                ['document_type' => 'other', 'document' => ['id' => 'doc_2']],
            ],
        ],
    ]
);
```

## Development

```bash
composer install
npm install
composer test
composer lint
```

## License

MIT
