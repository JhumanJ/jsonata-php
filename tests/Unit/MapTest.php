<?php

use JsonataPhp\ExpressionService;

it('preserves empty map sequences and explicit nulls like JavaScript', function (string $expression, mixed $expected) {
    $js = jsonata_test_evaluate_with_local_js($expression, []);

    expect($js['ok'])->toBeTrue();
    expect($js['result'])->toEqual($expected);
    expect((new ExpressionService)->evaluate($expression, []))->toEqual($expected);
})->with([
    'empty map in array constructor' => ['[$map([], function($v){$v})]', []],
    'empty map in object' => ['{"pieces": $map([], function($v){$v})}', []],
    'empty map is missing' => ['$exists($map([], function($v){$v}))', false],
    'assigned empty map' => ['($pieces := $map([], function($v){$v}); [$pieces])', []],
    'explicit null in array' => ['[null]', [null]],
    'callback returns null' => ['[$map([1], function($v){null})]', [null]],
    'callback returns missing' => ['[$map([1], function($v){$v.missing})]', []],
    'singleton map' => ['[$map([1], function($v){$v})]', [1]],
    'multiple map results' => ['[$map([1, 2], function($v){$v})]', [1, 2]],
]);

it('preserves an empty attachment list when returning a validated workflow payload', function () {
    $expression = <<<'JSONATA'
($lot := node.validate_final_cdj_connect_output.payload;
$merge([$lot,{"dossiers":[$map($lot.dossiers,function($d){
    $merge([$d,{"pieces_jointes":[$map($d.pieces_jointes,function($p){
        $sift($p,function($v,$k){$not($k in ["file_id","download_url","download_url_expires_at"])})
    })]}])
})]}]))
JSONATA;
    $lot = ['dossiers' => [
        ['reference_client' => 'EMAIL-ONLY', 'pieces_jointes' => []],
        ['reference_client' => 'WITH-PDF', 'pieces_jointes' => [
            ['reference_piece' => 'PDF-1', 'file_id' => 'file-1', '_file' => ['type' => 'fileRef', 'id' => 'file-1']],
        ]],
    ]];
    $context = ['node' => ['validate_final_cdj_connect_output' => ['payload' => $lot]]];
    $expected = $lot;
    unset($expected['dossiers'][1]['pieces_jointes'][0]['file_id']);
    $js = jsonata_test_evaluate_with_local_js($expression, $context);

    expect($js['ok'])->toBeTrue();
    expect($js['result'])->toEqual($expected);
    expect((new ExpressionService)->evaluate($expression, $context))->toEqual($expected);
});
