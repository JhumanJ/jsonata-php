<?php

namespace JsonataPhp\Builtins;

use JsonataPhp\EvaluationException;
use JsonataPhp\Evaluator;

trait RegistersNumericBuiltins
{
    /**
     * @param  array<string, mixed>  $rootContext
     * @return array<int, BuiltinDefinition>
     */
    protected function numericBuiltinDefinitions(Evaluator $evaluator, array $rootContext): array
    {
        return [
            $this->builtin('sum', function (array $arguments) use ($evaluator): int|float {
                $values = $evaluator->toSequence($arguments[0] ?? null);

                return array_reduce($values, function (int|float $carry, mixed $value): int|float {
                    return $carry + $this->toNumber($value);
                }, 0);
            }, '<a<n>:n>'),
            $this->builtin('number', fn (array $arguments): int|float => $this->toNumber($arguments[0] ?? null), '<(nsb)-:n>'),
            $this->builtin('abs', fn (array $arguments): int|float => abs($this->toNumber($arguments[0] ?? null)), '<n-:n>'),
            $this->builtin('floor', fn (array $arguments): int => (int) floor($this->toNumber($arguments[0] ?? null)), '<n-:n>'),
            $this->builtin('ceil', fn (array $arguments): int => (int) ceil($this->toNumber($arguments[0] ?? null)), '<n-:n>'),
            $this->builtin('round', function (array $arguments): float {
                $precision = (int) ($arguments[1] ?? 0);

                return round($this->toNumber($arguments[0] ?? null), $precision, PHP_ROUND_HALF_EVEN);
            }, '<n-n?:n>'),
            $this->builtin('min', fn (array $arguments): int|float|null => $this->minOrMax($evaluator->toSequence($arguments[0] ?? null), 'min'), '<a<n>:n>'),
            $this->builtin('max', fn (array $arguments): int|float|null => $this->minOrMax($evaluator->toSequence($arguments[0] ?? null), 'max'), '<a<n>:n>'),
            $this->builtin('average', function (array $arguments) use ($evaluator): int|float|null {
                $values = $this->numericSequence($evaluator->toSequence($arguments[0] ?? null));

                return $values === [] ? null : array_sum($values) / count($values);
            }, '<a<n>:n>'),
            $this->builtin('sqrt', function (array $arguments): float {
                $value = $this->toNumber($arguments[0] ?? null);
                if ($value < 0) {
                    throw new EvaluationException(
                        sprintf('Error D3060: The sqrt function cannot be applied to a negative number: %s.', $value),
                        'D3060'
                    );
                }

                return sqrt($value);
            }, '<n-:n>'),
            $this->builtin('power', function (array $arguments): int|float {
                $base = $this->toNumber($arguments[0] ?? null);
                $exponent = $this->toNumber($arguments[1] ?? null);
                $result = $base ** $exponent;

                if (is_infinite($result) || is_nan($result)) {
                    throw new EvaluationException(
                        sprintf('Error D3061: The power function produced an invalid JSON number for base=%s exponent=%s.', $base, $exponent),
                        'D3061'
                    );
                }

                return $result;
            }, '<n-n:n>'),
            $this->builtin('random', fn (): float => lcg_value(), '<:n>'),
            $this->builtin('formatNumber', function (array $arguments): string {
                $value = $this->toNumber($arguments[0] ?? null);
                $picture = (string) ($arguments[1] ?? '');

                return $this->numberFormatter->format($value, $picture);
            }, '<n-so?:s>'),
            $this->builtin('formatBase', function (array $arguments): string {
                $value = (int) $this->toNumber($arguments[0] ?? null);
                $radix = array_key_exists(1, $arguments) ? (int) $this->toNumber($arguments[1]) : 10;

                if ($radix < 2 || $radix > 36) {
                    throw new EvaluationException(
                        sprintf('Error D3100: The radix of the formatBase function must be between 2 and 36. It was given %d.', $radix),
                        'D3100'
                    );
                }

                return strtolower(base_convert((string) $value, 10, $radix));
            }, '<n-n?:s>'),
        ];
    }
}
