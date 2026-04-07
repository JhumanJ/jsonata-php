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
