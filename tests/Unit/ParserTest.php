<?php

use JsonataPhp\Lexer;
use JsonataPhp\Parser;

describe('Parser', function () {
    it('parses object literals with nested property access', function () {
        $lexer = (jsonata_test_resolve(Lexer::class));
        $parser = (jsonata_test_resolve(Parser::class));

        $ast = $parser->parse(
            $lexer->tokenize('{"label": workflow.workspace_name & "-" & input.index}')
        );

        expect($ast['type'])->toBe('object');
        expect($ast['pairs'])->toHaveCount(1);
        expect($ast['pairs'][0]['key']['type'])->toBe('literal');
        expect($ast['pairs'][0]['key']['value'])->toBe('label');
        expect($ast['pairs'][0]['value']['type'])->toBe('binary');
        expect($ast['pairs'][0]['value']['operator'])->toBe('&');
    });

    it('parses filtered projections followed by property projection', function () {
        $lexer = (jsonata_test_resolve(Lexer::class));
        $parser = (jsonata_test_resolve(Parser::class));

        $ast = $parser->parse(
            $lexer->tokenize('input.value[document_type = "requete-injonction" or document_type = "mise-en-demeure"].document')
        );

        expect($ast['type'])->toBe('property');
        expect($ast['name'])->toBe('document');
        expect($ast['target']['type'])->toBe('filter');
    });

    it('parses arithmetic precedence, array literals, membership, and ranges', function () {
        $lexer = (jsonata_test_resolve(Lexer::class));
        $parser = (jsonata_test_resolve(Parser::class));

        $ast = $parser->parse(
            $lexer->tokenize('[1 + 2 * 3, input.index >= 2, input.index in 1..3]')
        );

        expect($ast['type'])->toBe('array');
        expect($ast['items'])->toHaveCount(3);
        expect($ast['items'][0]['type'])->toBe('binary');
        expect($ast['items'][0]['operator'])->toBe('+');
        expect($ast['items'][0]['right']['type'])->toBe('binary');
        expect($ast['items'][0]['right']['operator'])->toBe('*');
        expect($ast['items'][1]['operator'])->toBe('>=');
        expect($ast['items'][2]['operator'])->toBe('in');
        expect($ast['items'][2]['right']['operator'])->toBe('..');
    });

    it('parses fallback operators with low precedence', function () {
        $lexer = (jsonata_test_resolve(Lexer::class));
        $parser = (jsonata_test_resolve(Parser::class));

        $ast = $parser->parse(
            $lexer->tokenize('files.byId["missing"].filename ?? "fallback" ?: "other"')
        );

        expect($ast['type'])->toBe('binary');
        expect($ast['operator'])->toBe('?:');
        expect($ast['left']['operator'])->toBe('??');
    });

    it('parses chained calls and grouped assignment sequences', function () {
        $lexer = (jsonata_test_resolve(Lexer::class));
        $parser = (jsonata_test_resolve(Parser::class));

        $chainAst = $parser->parse(
            $lexer->tokenize('"  Acme  " ~> $trim() ~> $lowercase()')
        );

        expect($chainAst['type'])->toBe('binary');
        expect($chainAst['operator'])->toBe('~>');
        expect($chainAst['left']['operator'])->toBe('~>');

        $sequenceAst = $parser->parse(
            $lexer->tokenize('($x := [1,2,3]; $x ~> $sum())')
        );

        expect($sequenceAst['type'])->toBe('grouping');
        expect($sequenceAst['expression']['type'])->toBe('sequence');
        expect($sequenceAst['expression']['expressions'][0]['type'])->toBe('assignment');
        expect($sequenceAst['expression']['expressions'][1]['operator'])->toBe('~>');
    });

    it('parses assignment right-hand ternaries inside grouped sequences', function () {
        $lexer = (jsonata_test_resolve(Lexer::class));
        $parser = (jsonata_test_resolve(Parser::class));

        $ast = $parser->parse(
            $lexer->tokenize('($x := foo ? bar : baz; {"value": $x})')
        );

        expect($ast['type'])->toBe('grouping');
        expect($ast['expression']['type'])->toBe('sequence');
        expect($ast['expression']['expressions'][0]['type'])->toBe('assignment');
        expect($ast['expression']['expressions'][0]['target']['name'])->toBe('$x');
        expect($ast['expression']['expressions'][0]['value']['type'])->toBe('conditional');
        expect($ast['expression']['expressions'][1]['type'])->toBe('object');
    });

    it('parses regex literals as literal primary expressions', function () {
        $lexer = (jsonata_test_resolve(Lexer::class));
        $parser = (jsonata_test_resolve(Parser::class));

        $ast = $parser->parse(
            $lexer->tokenize('$match("abc123", /[0-9]+/)')
        );

        expect($ast['type'])->toBe('call');
        expect($ast['arguments'][1]['type'])->toBe('literal');
        expect($ast['arguments'][1]['value'])->toBe([
            'pattern' => '[0-9]+',
            'modifiers' => '',
        ]);
    });

    it('parses wildcard descendant sort and power expressions', function () {
        $lexer = (jsonata_test_resolve(Lexer::class));
        $parser = (jsonata_test_resolve(Parser::class));

        $wildcardAst = $parser->parse(
            $lexer->tokenize('input.item.items.*')
        );

        expect($wildcardAst['type'])->toBe('wildcard');

        $descendantAst = $parser->parse(
            $lexer->tokenize('{"a": {"b": 1}}.**')
        );

        expect($descendantAst['type'])->toBe('descendant');

        $sortAst = $parser->parse(
            $lexer->tokenize('input.item.items^(<amount).document_type')
        );

        expect($sortAst['type'])->toBe('property');
        expect($sortAst['target']['type'])->toBe('sort');
        expect($sortAst['target']['terms'][0]['expression']['type'])->toBe('identifier');

        $powerAst = $parser->parse(
            $lexer->tokenize('2 ** 3 ** 2')
        );

        expect($powerAst['type'])->toBe('binary');
        expect($powerAst['operator'])->toBe('**');
        expect($powerAst['right']['operator'])->toBe('**');
    });

    it('parses focus and index binding with quoted property access', function () {
        $lexer = (jsonata_test_resolve(Lexer::class));
        $parser = (jsonata_test_resolve(Parser::class));

        $ast = $parser->parse(
            $lexer->tokenize('Account.Order.Product@$p#$i.{"name": $p."Product Name", "index": $i}')
        );

        expect($ast['type'])->toBe('object_map');
        expect($ast['object']['type'])->toBe('object');
        expect($ast['object']['pairs'][0]['value']['type'])->toBe('property');
        expect($ast['object']['pairs'][0]['value']['name'])->toBe('Product Name');
        expect($ast['object']['pairs'][0]['value']['target']['type'])->toBe('variable');
        expect($ast['object']['pairs'][1]['value']['type'])->toBe('variable');
    });

    it('parses parent path steps', function () {
        $lexer = (jsonata_test_resolve(Lexer::class));
        $parser = (jsonata_test_resolve(Parser::class));

        $ast = $parser->parse(
            $lexer->tokenize('Account.Order.Product.%.OrderID')
        );

        expect($ast['type'])->toBe('property');
        expect($ast['name'])->toBe('OrderID');
        expect($ast['target']['type'])->toBe('parent');
        expect($ast['target']['target']['type'])->toBe('property');
    });

    it('parses placeholders in function call arguments', function () {
        $lexer = (jsonata_test_resolve(Lexer::class));
        $parser = (jsonata_test_resolve(Parser::class));

        $ast = $parser->parse(
            $lexer->tokenize('$substring(?, ?, 2)')
        );

        expect($ast['type'])->toBe('partial');
        expect($ast['arguments'][0]['type'])->toBe('placeholder');
        expect($ast['arguments'][1]['type'])->toBe('placeholder');
        expect($ast['arguments'][2]['type'])->toBe('literal');
    });

    it('parses transform expressions with optional delete clauses', function () {
        $lexer = (jsonata_test_resolve(Lexer::class));
        $parser = (jsonata_test_resolve(Parser::class));

        $ast = $parser->parse(
            $lexer->tokenize('| items | {"name": $uppercase($.name)}, ["keep"] |')
        );

        expect($ast['type'])->toBe('transform');
        expect($ast['pattern']['type'])->toBe('identifier');
        expect($ast['update']['type'])->toBe('object');
        expect($ast['delete']['type'])->toBe('array');
    });
});
