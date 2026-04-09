<?php

use JsonataPhp\EvaluationException;
use JsonataPhp\ExpressionService;

describe('ExpressionService', function () {
    beforeEach(function () {
        $this->service = (jsonata_test_resolve(ExpressionService::class));
        $this->context = [
            'input' => [
                'index' => 2,
                'item' => [
                    'items' => [
                        ['document_type' => 'invoice', 'amount' => 10],
                        ['document_type' => 'receipt', 'amount' => 15],
                    ],
                ],
                'value' => [
                    [
                        'document_type' => 'requete-injonction',
                        'document' => [
                            'type' => 'splitDocumentRef',
                            'id' => 'doc_a',
                            'segmentIndex' => 2,
                        ],
                    ],
                    [
                        'document_type' => 'other',
                        'document' => [
                            'type' => 'splitDocumentRef',
                            'id' => 'doc_b',
                            'segmentIndex' => 3,
                        ],
                    ],
                    [
                        'document_type' => 'mise-en-demeure',
                        'document' => [
                            'type' => 'splitDocumentRef',
                            'id' => 'doc_c',
                            'segmentIndex' => 4,
                        ],
                    ],
                ],
            ],
            'json' => [
                'index' => 2,
                'item' => [
                    'items' => [
                        ['document_type' => 'invoice', 'amount' => 10],
                        ['document_type' => 'receipt', 'amount' => 15],
                    ],
                ],
                'value' => [
                    [
                        'document_type' => 'requete-injonction',
                        'document' => [
                            'type' => 'splitDocumentRef',
                            'id' => 'doc_a',
                            'segmentIndex' => 2,
                        ],
                    ],
                    [
                        'document_type' => 'other',
                        'document' => [
                            'type' => 'splitDocumentRef',
                            'id' => 'doc_b',
                            'segmentIndex' => 3,
                        ],
                    ],
                    [
                        'document_type' => 'mise-en-demeure',
                        'document' => [
                            'type' => 'splitDocumentRef',
                            'id' => 'doc_c',
                            'segmentIndex' => 4,
                        ],
                    ],
                ],
            ],
            'workflow' => [
                'workspace_name' => 'Acme',
            ],
            'node' => [
                'id' => 'data_transform_1',
                'config' => [
                    'params' => [],
                ],
            ],
            'files' => [
                'byId' => [
                    'file-123' => [
                        'filename' => 'invoice.pdf',
                    ],
                ],
            ],
            'documents' => [],
            'vars' => [],
            'nested' => [
                'groups' => [
                    [
                        'items' => [
                            ['name' => 'alpha'],
                            ['name' => 'beta'],
                        ],
                    ],
                    [
                        'items' => [
                            ['name' => 'gamma'],
                        ],
                    ],
                ],
            ],
            'Account' => [
                'Order' => [
                    'OrderID' => 'O1',
                    'Product' => [
                        ['Product Name' => 'A', 'Price' => 2],
                        ['Product Name' => 'B', 'Price' => 1],
                    ],
                ],
            ],
        ];
    });

    it('builds nested objects and concatenates strings', function () {
        $result = $this->service->evaluate('{
            "batch_index": input.index,
            "first_type": input.item.items[0].document_type,
            "workspace": workflow.workspace_name,
            "label": workflow.workspace_name & "-" & input.index,
            "nested": {
                "second_type": input.item.items[1].document_type
            }
        }', $this->context);

        expect($result)->toBe([
            'batch_index' => 2,
            'first_type' => 'invoice',
            'workspace' => 'Acme',
            'label' => 'Acme-2',
            'nested' => [
                'second_type' => 'receipt',
            ],
        ]);
    });

    it('projects arrays and reads helper roots', function () {
        $result = $this->service->evaluate('{
            "types": input.item.items.document_type,
            "node_id": node.id,
            "filename": $lookup(files.byId, "file-123").filename
        }', $this->context);

        expect($result)->toBe([
            'types' => ['invoice', 'receipt'],
            'node_id' => 'data_transform_1',
            'filename' => 'invoice.pdf',
        ]);
    });

    it('supports filtered projections without map for backend parity', function () {
        $result = $this->service->evaluate(
            'input.value[document_type = "requete-injonction" or document_type = "mise-en-demeure"].document',
            $this->context
        );

        expect($result)->toBe([
            [
                'type' => 'splitDocumentRef',
                'id' => 'doc_a',
                'segmentIndex' => 2,
            ],
            [
                'type' => 'splitDocumentRef',
                'id' => 'doc_c',
                'segmentIndex' => 4,
            ],
        ]);
    });

    it('supports map over filtered results like the frontend jsonata engine', function () {
        $result = $this->service->evaluate(
            '$map(input.value[document_type = "requete-injonction" or document_type = "mise-en-demeure"], function($segment) { $segment.document })',
            $this->context
        );

        expect($result)->toBe([
            [
                'type' => 'splitDocumentRef',
                'id' => 'doc_a',
                'segmentIndex' => 2,
            ],
            [
                'type' => 'splitDocumentRef',
                'id' => 'doc_c',
                'segmentIndex' => 4,
            ],
        ]);
    });

    it('matches JS for map callbacks that bind locals and return objects from a block', function () {
        $context = [
            'workflow' => [
                'successful_batches' => [
                    [
                        'batch_index' => 0,
                        'batch_label' => 'batch-1',
                        'document_count' => 3,
                        'extracted_data' => [
                            'btp_lignes_creance' => [
                                'memo_name' => 'Memo Back-office',
                                'creance_total' => 2243.8,
                            ],
                            'mise_en_demeure_periodicite' => [
                                'periodicite_texte_a_copier' => 'du 01/01/2024 au 30/06/2025',
                            ],
                        ],
                    ],
                ],
            ],
            'input' => [
                'meta' => [
                    'iterated_count' => 1,
                ],
            ],
            'json' => [
                'meta' => [
                    'iterated_count' => 1,
                ],
            ],
            'node' => [],
            'files' => [],
            'documents' => [],
            'vars' => [],
        ];

        $expression = <<<'JSONATA'
(
  $raw := function($field) {
    $type($field) = "object" and $exists($field.value) ? $field.value : $field
  };
  $map(workflow.successful_batches, function($batch) {
    (
      $creance := $batch.extracted_data.btp_lignes_creance ? $batch.extracted_data.btp_lignes_creance : {};
      $periodicite := $batch.extracted_data.mise_en_demeure_periodicite ? $batch.extracted_data.mise_en_demeure_periodicite : {};
      {
        "batch_number": $batch.batch_index + 1,
        "batch_reference": $batch.batch_label,
        "document_count": $batch.document_count,
        "memo_name": $raw($creance.memo_name),
        "creance_total": $raw($creance.creance_total),
        "period_text": $raw($periodicite.periodicite_texte_a_copier)
      }
    )
  })
)
JSONATA;

        $phpResult = $this->service->evaluate($expression, $context);
        $jsResult = jsonata_test_evaluate_with_local_js($expression, $context);

        expect($jsResult['ok'])->toBeTrue();
        expect($phpResult)->toEqual($jsResult['result']);
    });

    it('throws a jsonata-style syntax error for invalid expressions', function () {
        expect(fn () => $this->service->evaluate('{', $this->context))
            ->toThrow(EvaluationException::class, 'Error S0203');
    });

    it('supports arithmetic comparisons and array literals', function () {
        $result = $this->service->evaluate('{
            "math": 1 + 2 * 3,
            "threshold_met": input.index >= 2,
            "not_equal": workflow.workspace_name != "Globex",
            "in_range": input.index in 1..3,
            "range": 1..3,
            "array": [input.index - 1, input.index / 2, input.index % 2]
        }', $this->context);

        expect($result)->toBe([
            'math' => 7,
            'threshold_met' => true,
            'not_equal' => true,
            'in_range' => true,
            'range' => [1, 2, 3],
            'array' => [1, 1, 0.0],
        ]);
    });

    it('supports common builtin functions used in transformations', function () {
        $result = $this->service->evaluate('{
            "count": $count(input.value),
            "sum": $sum([1, 2, 3]),
            "exists": $exists(files.byId["file-123"]),
            "appended": $append([1, 2], [3, 4]),
            "stringified": $string({"id": node.id})
        }', $this->context);

        expect($result)->toBe([
            'count' => 3,
            'sum' => 6,
            'exists' => true,
            'appended' => [1, 2, 3, 4],
            'stringified' => '{"id":"data_transform_1"}',
        ]);
    });

    it('supports fallback operators and additional standard helpers', function () {
        $result = $this->service->evaluate('{
            "coalesce": files.byId["missing"].filename ?? "fallback",
            "elvis": files.byId["missing"].filename ?: "fallback",
            "lookup": $lookup(files.byId, "file-123").filename,
            "keys": $keys(files.byId),
            "distinct": $distinct(["invoice", "invoice", "receipt"]),
            "filtered_ids": $filter(input.value, function($segment) { $segment.document.id != "doc_b" }).document.id
        }', $this->context);

        expect($result)->toBe([
            'coalesce' => 'fallback',
            'elvis' => 'fallback',
            'lookup' => 'invoice.pdf',
            'keys' => 'file-123',
            'distinct' => ['invoice', 'receipt'],
            'filtered_ids' => ['doc_a', 'doc_c'],
        ]);
    });

    it('flattens chained list projections like jsonata path traversal', function () {
        $result = $this->service->evaluate('nested.groups.items.name', $this->context);

        expect($result)->toBe(['alpha', 'beta', 'gamma']);
    });

    it('supports additional collection and string builtins', function () {
        $result = $this->service->evaluate('{
            "filtered_ids": $filter(input.value, function($segment) { $segment.document.id != "doc_b" }).document.id,
            "lookup": $lookup(files.byId, "file-123").filename,
            "not_missing": $not($exists(files.byId["missing"])),
            "boolean_filter": $boolean(input.value[document_type = "other"]),
            "keys": $keys(files.byId),
            "merged": $merge([{"a": 1}, {"b": 2}]),
            "distinct": $distinct([1, 1, 2, 2, 3]),
            "reverse": $reverse([1, 2, 3]),
            "join": $join(["a", "b"], "-"),
            "contains": $contains(workflow.workspace_name, "cm"),
            "length": $length(workflow.workspace_name),
            "substring": $substring(workflow.workspace_name, 1, 2)
        }', $this->context);

        expect($result)->toBe([
            'filtered_ids' => ['doc_a', 'doc_c'],
            'lookup' => 'invoice.pdf',
            'not_missing' => false,
            'boolean_filter' => true,
            'keys' => 'file-123',
            'merged' => ['a' => 1, 'b' => 2],
            'distinct' => [1, 2, 3],
            'reverse' => [3, 2, 1],
            'join' => 'a-b',
            'contains' => true,
            'length' => 4,
            'substring' => 'cm',
        ]);
    });

    it('supports current and root context variables plus ternary conditionals', function () {
        $result = $this->service->evaluate('{
            "current_workspace": $.workflow.workspace_name,
            "root_workspace": $$.workflow.workspace_name,
            "status": input.index >= 2 ? "ok" : "ko"
        }', $this->context);

        expect($result)->toBe([
            'current_workspace' => 'Acme',
            'root_workspace' => 'Acme',
            'status' => 'ok',
        ]);
    });

    it('supports chain operators and grouped variable bindings', function () {
        expect($this->service->evaluate('"  Acme  " ~> $trim() ~> $lowercase()', $this->context))
            ->toBe('acme');

        expect($this->service->evaluate('($x := [1, 2, 3]; $x ~> $sum())', $this->context))
            ->toBe(6);

        expect($this->service->evaluate('($name := "Acme"; $name ~> $uppercase())', $this->context))
            ->toBe('ACME');
    });

    it('supports numeric casing and aggregation helpers', function () {
        $result = $this->service->evaluate('{
            "lower": $lowercase("Acme"),
            "upper": $uppercase("Acme"),
            "trimmed": $trim(" Acme "),
            "number": $number("12.5"),
            "abs": $abs(-3),
            "floor": $floor(2.8),
            "ceil": $ceil(2.2),
            "round": $round(2.345, 2),
            "min": $min([3, 1, 2]),
            "max": $max([3, 1, 2]),
            "average": $average([2, 4, 6]),
            "reduced": $reduce([1, 2, 3], function($acc, $value) { $acc + $value }, 0),
            "formatted_number": $formatNumber(12345.67, "#,##0.00"),
            "formatted_integer": $formatInteger(42, "0000"),
            "formatted_roman": $formatInteger(1999, "I"),
            "parsed_integer": $parseInteger("0042", "0000"),
            "parsed_roman": $parseInteger("MCMXCIX", "I")
        }', $this->context);

        expect($result)->toBe([
            'lower' => 'acme',
            'upper' => 'ACME',
            'trimmed' => 'Acme',
            'number' => 12.5,
            'abs' => 3,
            'floor' => 2,
            'ceil' => 3,
            'round' => 2.34,
            'min' => 1,
            'max' => 3,
            'average' => 4,
            'reduced' => 6,
            'formatted_number' => '12,345.67',
            'formatted_integer' => '0042',
            'formatted_roman' => 'MCMXCIX',
            'parsed_integer' => 42,
            'parsed_roman' => 1999,
        ]);
    });

    it('supports basic datetime helpers', function () {
        $result = $this->service->evaluate('{
            "millis": $toMillis("1970-01-01T00:00:00.000Z"),
            "iso": $fromMillis(0),
            "formatted": $fromMillis(0, "[Y0001]-[M01]-[D01]T[h01]:[m01]:[s01]Z"),
            "with_timezone": $fromMillis(1531228455123, "[Y0001]-[M01]-[D01] [H01]:[m01]:[s01].[f001]", "+0200")
        }', $this->context);

        expect($result)->toBe([
            'millis' => 0,
            'iso' => '1970-01-01T00:00:00.000Z',
            'formatted' => '1970-01-01T12:00:00Z',
            'with_timezone' => '2018-07-10 15:14:15.123',
        ]);
    });

    it('supports split replace and sort helpers', function () {
        $result = $this->service->evaluate('{
            "split": $split("a,b,c", ","),
            "replace": $replace("ababa", "ba", "X"),
            "sort_numbers": $sort([3, 1, 2]),
            "sort_strings": $sort(["b", "a", "c"])
        }', $this->context);

        expect($result)->toBe([
            'split' => ['a', 'b', 'c'],
            'replace' => 'aXX',
            'sort_numbers' => [1, 2, 3],
            'sort_strings' => ['a', 'b', 'c'],
        ]);
    });

    it('supports regex-aware string helpers', function () {
        $result = $this->service->evaluate('{
            "contains": $contains("abc123", /[0-9]+/),
            "split": $split("a1b22c", /[0-9]+/),
            "replace": $replace("a1b22c", /[0-9]+/, "X"),
            "match": $match("a1b22c", /[0-9]+/)
        }', $this->context);

        expect($result)->toBe([
            'contains' => true,
            'split' => ['a', 'b', 'c'],
            'replace' => 'aXbXc',
            'match' => [
                ['match' => '1', 'index' => 1, 'groups' => []],
                ['match' => '22', 'index' => 3, 'groups' => []],
            ],
        ]);
    });

    it('supports dynamic current-time helpers', function () {
        $result = $this->service->evaluate('{
            "now": $now(),
            "millis": $millis()
        }', $this->context);

        expect($result['now'])->toBeString();
        expect($result['now'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/');
        expect($result['millis'])->toBeInt();
        expect($result['millis'])->toBeGreaterThan(0);
    });

    it('supports additional string object and utility builtins', function () {
        $result = $this->service->evaluate('{
            "before": $substringBefore("abc:def", ":"),
            "after": $substringAfter("abc:def", ":"),
            "pad_right": $pad("A", 3, "."),
            "pad_left": $pad("A", -3, "."),
            "sqrt": $sqrt(9),
            "power": $power(2, 3),
            "zip": $zip([1,2], [3,4]),
            "spread": $spread({"a": 1, "b": 2}),
            "each": $each({"a": 1, "b": 2}, function($v, $k) { $k & ":" & $string($v) }),
            "sift": $sift({"a": 1, "b": 0}, function($v) { $boolean($v) }),
            "single": $single([1,2,3], function($v) { $v = 2 }),
            "type_object": $type({"a": 1}),
            "type_regex": $type(/[0-9]+/),
            "clone": $clone({"a": [1,2]}),
            "base64": $base64encode("abc"),
            "decoded": $base64decode("YWJj"),
            "encoded_url": $encodeUrlComponent("a b"),
            "decoded_url": $decodeUrlComponent("a%20b")
        }', $this->context);

        expect($result)->toBe([
            'before' => 'abc',
            'after' => 'def',
            'pad_right' => 'A..',
            'pad_left' => '..A',
            'sqrt' => 3.0,
            'power' => 8,
            'zip' => [[1, 3], [2, 4]],
            'spread' => [['a' => 1], ['b' => 2]],
            'each' => ['a:1', 'b:2'],
            'sift' => ['a' => 1],
            'single' => 2,
            'type_object' => 'object',
            'type_regex' => 'function',
            'clone' => ['a' => [1, 2]],
            'base64' => 'YWJj',
            'decoded' => 'abc',
            'encoded_url' => 'a%20b',
            'decoded_url' => 'a b',
        ]);
    });

    it('supports signature-driven context defaults and utility error paths', function () {
        expect($this->service->evaluate('$formatBase(255, 16)', $this->context))
            ->toBe('ff');

        expect(fn () => $this->service->evaluate('$parseInteger("abc", "0000")', $this->context))
            ->toThrow(EvaluationException::class, 'Error D3131');

        expect($this->service->evaluate('$sort([{"n":2},{"n":1}], function($l, $r) { $l.n > $r.n })', $this->context))
            ->toBe([
                ['n' => 1],
                ['n' => 2],
            ]);

        expect(fn () => $this->service->evaluate('$single([1,2,3], function($v) { $v > 3 })', $this->context))
            ->toThrow(EvaluationException::class, 'Error D3139');

        expect(fn () => $this->service->evaluate('$assert(false, "boom")', $this->context))
            ->toThrow(EvaluationException::class, 'Error D3141');

        expect(fn () => $this->service->evaluate('$error("stop")', $this->context))
            ->toThrow(EvaluationException::class, 'Error D3137');
    });

    it('supports wildcard descendant sort and power expressions', function () {
        $result = $this->service->evaluate('{
            "wildcard": input.item.items.*,
            "descendant": {"a": {"b": 1}}.**,
            "sorted_types": input.item.items^(<amount).document_type,
            "sorted_numbers": [3,1,2]^(<$).$,
            "power": 2 ** 3 ** 2
        }', $this->context);

        expect($result)->toBe([
            'wildcard' => ['invoice', 10, 'receipt', 15],
            'descendant' => [
                ['a' => ['b' => 1]],
                ['b' => 1],
                1,
            ],
            'sorted_types' => ['invoice', 'receipt'],
            'sorted_numbers' => [1, 2, 3],
            'power' => 512,
        ]);
    });

    it('supports focus and index binding operators', function () {
        expect($this->service->evaluate(
            'Account.Order.Product@$p#$i.{"name": $p."Product Name", "index": $i}',
            $this->context
        ))->toBe([
            ['name' => 'A', 'index' => 0],
            ['name' => 'B', 'index' => 1],
        ]);

        expect($this->service->evaluate(
            'Account.Order.Product@$p#$i[$i=0].{"name": $p."Product Name", "index": $i}',
            $this->context
        ))->toBe([
            'name' => 'A',
            'index' => 0,
        ]);

        expect($this->service->evaluate(
            'Account.Order.Product#$i.{"name": $."Product Name", "index": $i}',
            $this->context
        ))->toBe([
            ['name' => 'A', 'index' => 0],
            ['name' => 'B', 'index' => 1],
        ]);

        expect($this->service->evaluate(
            'Account.Order.Product.%.OrderID',
            $this->context
        ))->toBe(['O1', 'O1']);

        expect($this->service->evaluate(
            'Account.Order.Product.%@$o.{"order": $o.OrderID}',
            $this->context
        ))->toBe([
            ['order' => 'O1'],
            ['order' => 'O1'],
        ]);
    });

    it('supports partial function application and placeholder piping', function () {
        expect($this->service->evaluate('$substring(?, 0, 2)("Acme")', $this->context))
            ->toBe('Ac');

        expect($this->service->evaluate('$substring(?, ?, 2)("Acme", 1)', $this->context))
            ->toBe('cm');

        expect($this->service->evaluate('$trim(?)("  Acme  ")', $this->context))
            ->toBe('Acme');

        expect($this->service->evaluate('"Acme" ~> $substring(?, 0, 2)', $this->context))
            ->toBe('Ac');

        expect($this->service->evaluate('($fn := $power(?, ?); $fn(2, 3))', $this->context))
            ->toBe(8);

        expect($this->service->evaluate('$map(["Acme", "Beta"], $substring(?, 0, 1))', $this->context))
            ->toBe(['A', 'B']);
    });

    it('supports lambda aliases and transform expressions', function () {
        expect($this->service->evaluate('(λ($v){$uppercase($v)})("acme")', $this->context))
            ->toBe('ACME');

        expect($this->service->evaluate('$map(["a","b"], λ($v){$uppercase($v)})', $this->context))
            ->toBe(['A', 'B']);

        expect($this->service->evaluate('$map([" alpha ", " beta "], $trim ~> $uppercase)', $this->context))
            ->toBe(['ALPHA', 'BETA']);

        expect($this->service->evaluate(
            '({"items":[{"name":"a","keep":true},{"name":"b","keep":false}]}) ~> | items | {"name": $uppercase($.name)} |',
            $this->context
        ))->toBe([
            'items' => [
                ['name' => 'A', 'keep' => true],
                ['name' => 'B', 'keep' => false],
            ],
        ]);

        expect($this->service->evaluate(
            '({"items":[{"name":"a","keep":true},{"name":"b","keep":false}]}) ~> | items | {"name": $uppercase($.name)}, ["keep"] |',
            $this->context
        ))->toBe([
            'items' => [
                ['name' => 'A'],
                ['name' => 'B'],
            ],
        ]);

        $source = ['items' => [['n' => 1]]];

        expect($this->service->evaluate(
            '({"items":[{"n":1}]}) ~> | $lookup($, "items") | {"hit": true} |',
            $this->context
        ))->toBe([
            'items' => [
                ['n' => 1, 'hit' => true],
            ],
        ]);

        expect($this->service->evaluate(
            '({"items":[{"n":1}]}) ~> | ($x := items; $x) | {"hit": true} |',
            $this->context
        ))->toBe([
            'items' => [
                ['n' => 1, 'hit' => true],
            ],
        ]);

        $result = $this->service->evaluate('$ ~> | items | {"hit": true} |', $source);

        expect($result)->toBe([
            'items' => [
                ['n' => 1, 'hit' => true],
            ],
        ]);
        expect($source)->toBe(['items' => [['n' => 1]]]);
    });

    it('supports subscripting singleton path results like the JS engine', function () {
        $context = [
            'input' => [
                'index' => 0,
                'item' => [
                    'index' => 0,
                    'items' => [
                        ['document_type' => 'invoice', 'segment_index' => 1],
                    ],
                ],
            ],
        ];

        expect($this->service->evaluate('input.item.items[0].document_type', $context))
            ->toBe('invoice');

        expect($this->service->evaluate(
            '{"batch_index": input.index, "document_type": input.item.items[0].document_type, "segment_index": input.item.items[0].segment_index}',
            $context
        ))->toBe([
            'batch_index' => 0,
            'document_type' => 'invoice',
            'segment_index' => 1,
        ]);
    });

    it('supports inline function path steps and parent-context path steps', function () {
        expect($this->service->evaluate(
            '[1..3].function($x,$y)<n-n:n>{$x+$y}(4)',
            $this->context
        ))->toBe([5, 6, 7]);

        expect($this->service->evaluate(
            'Account.Order.Product.( $parent := %; $parent.OrderID )',
            $this->context
        ))->toBe(['O1', 'O1']);

        expect($this->service->evaluate(
            'Account.Order.Product.[`Product Name`, %.OrderID]',
            $this->context
        ))->toBe(['A', 'O1', 'B', 'O1']);
    });

    it('preserves parent semantics across focus-bound sibling traversals', function () {
        $library = json_decode(file_get_contents('/tmp/jsonata-upstream/test/test-suite/datasets/library.json'), true);

        expect($this->service->evaluate(
            'library.loans@$L.books@$B[$L.isbn=$B.isbn].{ "book": $B.title, "parent": $keys(%) }',
            $library
        ))->toBe([
            ['book' => 'Structure and Interpretation of Computer Programs', 'parent' => ['books', 'loans', 'customers']],
            ['book' => 'Compilers: Principles, Techniques, and Tools', 'parent' => ['books', 'loans', 'customers']],
            ['book' => 'Structure and Interpretation of Computer Programs', 'parent' => ['books', 'loans', 'customers']],
        ]);

        expect($this->service->evaluate(
            'library.loans@$L.books@$B[$L.isbn=$B.isbn].customers@$C[$C.id=$L.customer].{ "book": $B.title, "customer": $C.name, "grandparent": $keys(%.%) }',
            $library
        ))->toBe([
            ['book' => 'Structure and Interpretation of Computer Programs', 'customer' => 'Joe Doe', 'grandparent' => 'library'],
            ['book' => 'Compilers: Principles, Techniques, and Tools', 'customer' => 'Jason Arthur', 'grandparent' => 'library'],
            ['book' => 'Structure and Interpretation of Computer Programs', 'customer' => 'Jason Arthur', 'grandparent' => 'library'],
        ]);
    });
});
