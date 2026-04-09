# jsonata-php

`jsonata-php` is a standalone PHP port of [jsonata-js/jsonata](https://github.com/jsonata-js/jsonata), extracted from the internal Raydocs workflow engine so it can evolve as a public package.

The goal of this repository is pragmatic parity with the JavaScript engine for the JSONata surface used in production, with PHP-native tests and explicit parity checks against the upstream JS runtime.

## Status

This is an actively developed port, not yet a byte-for-byte reimplementation of every upstream code path.

What is already included:

- Lexer, parser and evaluator for a broad JSONata subset
- Built-in functions for strings, collections, objects, numeric helpers, encoding, regex, datetime, formatting and evaluation
- Support for transforms, partial application, lambda aliases, parent operator, tuple bindings, wildcard/descendant traversal and higher-order composition
- Parity tests that compare the PHP runtime against the `jsonata` npm package

Recent parity improvements include:

- correct precedence between assignment (`:=`) and conditional (`? :`) expressions inside grouped blocks
- JS-aligned `$map()` singleton collapsing, which matters for object-producing callbacks
- regression coverage for block-scoped lambda callbacks that bind locals and return projected objects

## Compatibility Matrix

| Area | Status | Notes |
| --- | --- | --- |
| Parser and core expressions | Partial | Broad operator/path coverage, including recent fixes for assignment/conditional precedence inside grouped expressions, but parser recovery and exact AST parity are still being expanded. |
| Paths and selectors | Partial | Includes `@`, `#`, `%`, `*`, `**`, projections, filters and tuple-aware traversal. |
| Standard library functions | Partial | Large builtin surface is present, with upstream-themed parity coverage growing function by function. |
| Signatures and coercions | Partial | Signature validation exists and now has dedicated upstream fixture coverage, but edge-case mismatch parity is still in progress. |
| Regex | Partial | Core regex matching, splitting, replacing and `$match` are implemented, with upstream fixture coverage for representative cases. |
| Datetime and formatting | Partial | `toMillis`, `fromMillis`, integer formatting/parsing and number formatting are available, but advanced picture/timezone parity remains incomplete. |
| Higher-order functions and closures | Partial | Lambdas, closures, partial application and the common HOF helpers are implemented, including JS-aligned `$map()` behavior for singleton object results, with upstream and local parity coverage. |
| Transforms | Partial | Transform expressions are supported, with focused upstream parity fixtures for nested update scenarios. |
| Error model | Partial | JSONata-style codes are present, but full 1:1 message/token/offset parity still needs deeper auditing. |

The repository now includes a structured upstream-fixture parity layer in `tests/Unit/UpstreamParityTest.php`, grouped by theme (`functions`, `datetime`, `higher-order`, `paths`, `regex`, `transforms`, `errors`). It clones `jsonata-js/jsonata` into the system temp directory when needed so the test suite can exercise real upstream fixtures locally.

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
