<?php

use JsonataPhp\Lexer;

describe('Lexer', function () {
    it('tokenizes a filtered map expression with functions and variables', function () {
        $lexer = (jsonata_test_resolve(Lexer::class));

        $tokens = $lexer->tokenize(
            '$map(input.value[document_type = "requete-injonction" or document_type = "mise-en-demeure"], function($segment) { $segment.document })'
        );

        expect(array_column($tokens, 'type'))->toBe([
            'variable',
            '(',
            'identifier',
            '.',
            'identifier',
            '[',
            'identifier',
            'operator',
            'string',
            'operator',
            'identifier',
            'operator',
            'string',
            ']',
            ',',
            'keyword',
            '(',
            'variable',
            ')',
            '{',
            'variable',
            '.',
            'identifier',
            '}',
            ')',
            'eof',
        ]);
    });

    it('tokenizes arithmetic and comparison operators with decimal numbers', function () {
        $lexer = (jsonata_test_resolve(Lexer::class));

        $tokens = $lexer->tokenize('[1.5 + 2, input.index >= 2, workflow.workspace_name != "Acme"]');

        expect(array_column($tokens, 'type'))->toBe([
            '[',
            'number',
            'operator',
            'number',
            ',',
            'identifier',
            '.',
            'identifier',
            'operator',
            'number',
            ',',
            'identifier',
            '.',
            'identifier',
            'operator',
            'string',
            ']',
            'eof',
        ]);

        expect($tokens[1]['value'])->toBe(1.5);
        expect($tokens[8]['value'])->toBe('>=');
        expect($tokens[14]['value'])->toBe('!=');
    });

    it('tokenizes fallback operators', function () {
        $lexer = (jsonata_test_resolve(Lexer::class));

        $tokens = $lexer->tokenize('files.byId["missing"].filename ?? "fallback" ?: "other"');

        expect(array_column($tokens, 'value'))->toContain('??', '?:');
    });

    it('tokenizes chain and assignment operators', function () {
        $lexer = (jsonata_test_resolve(Lexer::class));

        $tokens = $lexer->tokenize('($x := [1,2,3]; $x ~> $sum())');

        expect(array_column($tokens, 'value'))->toContain(':=', ';', '~>');
    });

    it('tokenizes regex literals in function arguments', function () {
        $lexer = (jsonata_test_resolve(Lexer::class));

        $tokens = $lexer->tokenize('$match("abc123", /[0-9]+/)');

        expect(array_column($tokens, 'type'))->toContain('regex');
        expect($tokens[4]['value'])->toBe([
            'pattern' => '[0-9]+',
            'modifiers' => '',
        ]);
    });

    it('tokenizes wildcard sort and power operators', function () {
        $lexer = (jsonata_test_resolve(Lexer::class));

        $tokens = $lexer->tokenize('input.item.items.*^(<amount).value ** 2');

        expect(array_column($tokens, 'value'))->toContain('*', '^', '<', '**');
    });

    it('tokenizes focus and index binding operators with quoted properties', function () {
        $lexer = (jsonata_test_resolve(Lexer::class));

        $tokens = $lexer->tokenize('Account.Order.Product@$p#$i.{"name": $p."Product Name", "index": $i}');

        expect(array_column($tokens, 'value'))->toContain('@', '#', 'Product Name');
    });

    it('tokenizes partial application placeholders', function () {
        $lexer = (jsonata_test_resolve(Lexer::class));

        $tokens = $lexer->tokenize('$substring(?, 0, 2)("Acme")');

        expect(array_column($tokens, 'value'))->toContain('?');
    });

    it('tokenizes lambda aliases as function keywords', function () {
        $lexer = (jsonata_test_resolve(Lexer::class));

        $tokens = $lexer->tokenize('λ($v){$uppercase($v)}');

        expect($tokens[0]['type'])->toBe('keyword');
        expect($tokens[0]['value'])->toBe('function');
    });

    it('tokenizes parent operators after path steps', function () {
        $lexer = (jsonata_test_resolve(Lexer::class));

        $tokens = $lexer->tokenize('Account.Order.Product.Price.%');

        expect(array_column($tokens, 'value'))->toContain('%');
    });
});
