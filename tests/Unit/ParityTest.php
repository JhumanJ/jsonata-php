<?php

use JsonataPhp\ExpressionService;
use Symfony\Component\Process\Process;

describe('Jsonata PHP and JS parity', function () {
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
                        ],
                    ],
                    [
                        'document_type' => 'other',
                        'document' => [
                            'type' => 'splitDocumentRef',
                            'id' => 'doc_b',
                        ],
                    ],
                    [
                        'document_type' => 'mise-en-demeure',
                        'document' => [
                            'type' => 'splitDocumentRef',
                            'id' => 'doc_c',
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
                        ],
                    ],
                    [
                        'document_type' => 'other',
                        'document' => [
                            'type' => 'splitDocumentRef',
                            'id' => 'doc_b',
                        ],
                    ],
                    [
                        'document_type' => 'mise-en-demeure',
                        'document' => [
                            'type' => 'splitDocumentRef',
                            'id' => 'doc_c',
                        ],
                    ],
                ],
            ],
            'workflow' => [
                'workspace_name' => 'Acme',
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
        ];
    });

    it('matches the local JS engine for supported expressions', function (string $expression) {
        $phpResult = $this->service->evaluate($expression, $this->context);
        $jsResult = evaluateWithLocalJsJsonata($expression, $this->context);

        expect($phpResult)->toEqual($jsResult);
    })->with([
        'projection' => 'input.item.items.document_type',
        'singleton subscript after collapsed path' => 'input.item.items[0].document_type',
        'filtered projection' => 'input.value[document_type = "requete-injonction" or document_type = "mise-en-demeure"].document',
        'map callback' => '$map(input.value[document_type != "other"], function($segment) { $segment.document.id })',
        'filter callback' => '$filter(input.value, function($segment) { $segment.document.id != "doc_b" }).document.id',
        'object build' => '{"label": workflow.workspace_name & "-" & input.index, "first": input.item.items[0].document_type}',
        'math and comparisons' => '{"sum": 1 + 2 * 3, "gte": input.index >= 2, "neq": input.index != 5}',
        'builtins' => '{"count": $count(input.item.items), "sum": $sum([1, 2, 3]), "append": $append([1, 2], [3, 4]), "exists": $exists(files.byId["file-123"])}',
        'lookup and booleans' => '{"lookup": $lookup(files.byId, "file-123").filename, "not_missing": $not($exists(files.byId["missing"])), "truthy": $boolean(input.value[document_type = "other"])}',
        'collection builtins' => '{"keys": $keys(files.byId), "merged": $merge([{"a": 1}, {"b": 2}]), "distinct": $distinct([1, 1, 2, 2, 3]), "reverse": $reverse([1, 2, 3])}',
        'string builtins' => '{"join": $join(["a", "b"], "-"), "contains": $contains(workflow.workspace_name, "cm"), "length": $length(workflow.workspace_name), "substring": $substring(workflow.workspace_name, 1, 2)}',
        'numeric builtins' => '{"lower": $lowercase("Acme"), "upper": $uppercase("Acme"), "trimmed": $trim(" Acme "), "number": $number("12.5"), "abs": $abs(-3), "floor": $floor(2.8), "ceil": $ceil(2.2), "round": $round(2.345, 2), "min": $min([3, 1, 2]), "max": $max([3, 1, 2]), "average": $average([2, 4, 6]), "reduced": $reduce([1, 2, 3], function($acc, $value) { $acc + $value }, 0)}',
        'format and parse builtins' => '{"format_number": $formatNumber(12345.67, "#,##0.00"), "format_integer": $formatInteger(42, "0000"), "format_roman": $formatInteger(1999, "I"), "parse_integer": $parseInteger("0042", "0000"), "parse_roman": $parseInteger("MCMXCIX", "I")}',
        'fallback operators' => '{"coalesce": files.byId["missing"].filename ?? "fallback", "elvis": files.byId["missing"].filename ?: "fallback"}',
        'context variables and ternary' => '{"current": $.workflow.workspace_name, "root": $$.workflow.workspace_name, "status": input.index >= 2 ? "ok" : "ko"}',
        'chain operators' => '{"trimmed": "  Acme  " ~> $trim() ~> $lowercase(), "nested": $lookup({"a": {"b": 1}}, "a") ~> $lookup("b")}',
        'assignment sequences' => '{"sum": ($x := [1,2,3]; $x ~> $sum()), "name": ($name := "Acme"; $name ~> $uppercase())}',
        'datetime helpers' => '{"millis": $toMillis("1970-01-01T00:00:00.000Z"), "iso": $fromMillis(0), "formatted": $fromMillis(0, "[Y0001]-[M01]-[D01]T[h01]:[m01]:[s01]Z"), "with_timezone": $fromMillis(1531228455123, "[Y0001]-[M01]-[D01] [H01]:[m01]:[s01].[f001]", "+0200")}',
        'split replace sort' => '{"split": $split("a,b,c", ","), "replace": $replace("ababa", "ba", "X"), "sort_numbers": $sort([3,1,2]), "sort_strings": $sort(["b","a","c"])}',
        'regex helpers' => '{"contains": $contains("abc123", /[0-9]+/), "split": $split("a1b22c", /[0-9]+/), "replace": $replace("a1b22c", /[0-9]+/, "X"), "match": $match("a1b22c", /[0-9]+/)}',
        'extended builtins' => '{"before": $substringBefore("abc:def", ":"), "after": $substringAfter("abc:def", ":"), "pad": $pad("A", -3, "."), "sqrt": $sqrt(9), "zip": $zip([1,2], [3,4]), "spread": $spread({"a":1,"b":2}), "type": $type({"a":1}), "base64": $base64encode("abc"), "base64decoded": $base64decode("YWJj"), "encoded": $encodeUrlComponent("a b"), "decoded": $decodeUrlComponent("a%20b"), "each": $each({"a":1,"b":2}, function($v, $k){$k & ":" & $string($v)}), "sift": $sift({"a":1,"b":0}, function($v){$boolean($v)}), "single": $single([1,2,3], function($v){$v=2}), "clone": $clone({"a":[1,2]})}',
        'signature and meta builtins' => '{"trim_context": ("  Acme  " ~> $trim()), "format_base": $formatBase(255, 16), "type_regex": $type(/[0-9]+/), "exists_missing": $exists(files.byId["missing"])}',
        'custom sort and eval builtins' => '{"sorted": $sort([{"n":2},{"n":1}], function($l, $r){$l.n > $r.n}), "evaluated": $eval("1+2")}',
        'encoding builtins' => '{"encoded_url": $encodeUrl("https://example.com/a b?x=1&y=2"), "decoded_url": $decodeUrl("https://example.com/a%20b?x=1&y=2")}',
        'focus and index binding' => 'Account.Order.Product@$p#$i.{"name": $p."Product Name", "index": $i}',
        'parent operator' => '{"product_orders": Account.Order.Product.%.OrderID, "price_parent_names": Account.Order.Product.Price.%."Product Name"}',
        'partial application and chain composition' => '{"partial_call": $substring(?, 0, 2)("Acme"), "pipe_partial": "Acme" ~> $substring(?, 0, 2), "composed": ($trim ~> $uppercase)("  acme  "), "mapped_partial": $map(["Acme","Beta"], $substring(?, 0, 1)), "mapped_composed": $map([" alpha "," beta "], $trim ~> $uppercase)}',
        'lambda alias and transform' => '{"lambda": (λ($v){$uppercase($v)})("acme"), "mapped": $map(["a","b"], λ($v){$uppercase($v)}), "transformed": (({"items":[{"name":"a","keep":true},{"name":"b","keep":false}]}) ~> | items | {"name": $uppercase($.name)}, ["keep"] |).items.name, "lookup_pattern": (({"items":[{"n":1}]}) ~> | $lookup($, "items") | {"hit": true} |).items.hit, "sequence_pattern": (({"items":[{"n":1}]}) ~> | ($x := items; $x) | {"hit": true} |).items.hit}',
    ]);
});

function evaluateWithLocalJsJsonata(string $expression, array $context): mixed
{
    $script = <<<'JS'
async function main() {
  const jsonataPath = process.argv[1];
  const expression = process.argv[2];
  const context = JSON.parse(process.argv[3]);
  const jsonata = require(jsonataPath);
  const compiled = jsonata(expression);
  const result = await compiled.evaluate(context);
  process.stdout.write(JSON.stringify(result));
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
        package_path('node_modules/jsonata/jsonata.js'),
        $expression,
        json_encode($context, JSON_THROW_ON_ERROR),
    ], package_path('.'));

    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
}
