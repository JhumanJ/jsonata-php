<?php

use JsonataPhp\Builtins\Signature;
use JsonataPhp\EvaluationException;
use JsonataPhp\Evaluator;

describe('Signature', function () {
    beforeEach(function () {
        $this->evaluator = (jsonata_test_resolve(Evaluator::class));
    });

    it('uses the current context when a signature marks an argument as context-aware', function () {
        $signature = Signature::parse('<s-:s>');

        expect($signature->validate([], '  Acme  ', $this->evaluator))
            ->toBe(['  Acme  ']);
    });

    it('wraps scalar values into arrays for array signatures', function () {
        $signature = Signature::parse('<a<n>:n>');

        expect($signature->validate([3], null, $this->evaluator))
            ->toBe([[3]]);
    });

    it('rejects invalid array subtype values', function () {
        $signature = Signature::parse('<a<n>:n>');

        expect(fn () => $signature->validate([['a', 'b']], null, $this->evaluator))
            ->toThrow(EvaluationException::class, 'Error T0412');
    });

    it('rejects invalid context fallback types', function () {
        $signature = Signature::parse('<s-:s>');

        expect(fn () => $signature->validate([], 42, $this->evaluator))
            ->toThrow(EvaluationException::class, 'Error T0411');
    });
});
