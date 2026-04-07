<?php

namespace JsonataPhp\Builtins;

use Closure;
use JsonataPhp\EvaluationException;
use JsonataPhp\Evaluator;

trait RegistersCollectionBuiltins
{
    /**
     * @param  array<string, mixed>  $rootContext
     * @return array<int, BuiltinDefinition>
     */
    protected function collectionBuiltinDefinitions(Evaluator $evaluator, array $rootContext): array
    {
        return [
            $this->builtin('map', function (array $arguments) use ($evaluator): mixed {
                [$sequence, $callback] = $arguments;
                $items = $evaluator->toSequence($sequence);
                if ($items === []) {
                    return null;
                }

                $results = [];
                foreach ($items as $index => $item) {
                    $mapped = $callback($this->support->hofArguments($callback, $item, $index, $items), $item);
                    if (! $evaluator->isMissing($mapped)) {
                        $results[] = $mapped;
                    }
                }

                return $results;
            }, '<af>'),
            $this->builtin('filter', function (array $arguments) use ($evaluator): mixed {
                [$sequence, $callback] = $arguments;
                $results = [];

                foreach ($evaluator->toSequence($sequence) as $index => $item) {
                    if ($evaluator->isTruthyPublic($callback($this->support->hofArguments($callback, $item, $index, $evaluator->toSequence($sequence)), $item))) {
                        $results[] = $item;
                    }
                }

                return $evaluator->collapseSequence($results);
            }, '<af>'),
            $this->builtin('count', fn (array $arguments): int => count($evaluator->toSequence($arguments[0] ?? null)), '<a:n>'),
            $this->builtin('append', function (array $arguments) use ($evaluator): mixed {
                return $evaluator->collapseSequence([
                    ...$evaluator->toSequence($arguments[0] ?? null),
                    ...$evaluator->toSequence($arguments[1] ?? null),
                ]);
            }, '<xx:a>'),
            $this->builtin('reverse', function (array $arguments) use ($evaluator): mixed {
                return $evaluator->collapseSequence(array_reverse($evaluator->toSequence($arguments[0] ?? null)));
            }, '<a:a>'),
            $this->builtin('distinct', function (array $arguments) use ($evaluator): mixed {
                return $this->distinctValues($evaluator->toSequence($arguments[0] ?? null), $evaluator);
            }, '<x:x>'),
            $this->builtin('sort', function (array $arguments) use ($evaluator): mixed {
                $items = $evaluator->toSequence($arguments[0] ?? null);
                $callback = $arguments[1] ?? null;
                $sorted = $items;

                if ($callback instanceof Closure) {
                    usort($sorted, function (mixed $left, mixed $right) use ($callback): int {
                        $decision = $callback([$left, $right], $left);

                        return $decision ? 1 : -1;
                    });

                    return $evaluator->collapseSequence($sorted);
                }

                $types = array_unique(array_map(
                    fn (mixed $value): string => is_int($value) || is_float($value) ? 'number' : (is_string($value) ? 'string' : 'other'),
                    $sorted
                ));

                if ($sorted !== [] && $types !== ['number'] && $types !== ['string']) {
                    throw new EvaluationException(
                        'Error D3070: The single argument form of the sort function can only be applied to an array of strings or an array of numbers.  Use the second argument to specify a comparison function',
                        'D3070'
                    );
                }

                usort($sorted, function (mixed $left, mixed $right): int {
                    if ((is_int($left) || is_float($left)) && (is_int($right) || is_float($right))) {
                        return $left <=> $right;
                    }

                    return strcmp((string) $left, (string) $right);
                });

                return $evaluator->collapseSequence($sorted);
            }, '<af?:a>'),
            $this->builtin('zip', function (array $arguments): array {
                $arrays = array_map(
                    fn (mixed $value): array => is_array($value) && array_is_list($value) ? array_values($value) : [$value],
                    $arguments
                );

                if ($arrays === []) {
                    return [];
                }

                $length = min(array_map('count', $arrays));
                $result = [];

                for ($index = 0; $index < $length; $index++) {
                    $tuple = [];
                    foreach ($arrays as $array) {
                        $tuple[] = $array[$index];
                    }
                    $result[] = $tuple;
                }

                return $result;
            }, '<a+>'),
            $this->builtin('single', function (array $arguments) use ($evaluator): mixed {
                $items = $evaluator->toSequence($arguments[0] ?? null);
                $callback = $arguments[1] ?? null;

                $matches = [];
                if ($callback instanceof Closure) {
                    foreach ($items as $index => $item) {
                        if ($evaluator->isTruthyPublic($callback($this->support->hofArguments($callback, $item, $index, $items), $item))) {
                            $matches[] = $item;
                        }
                    }
                } else {
                    $matches = $items;
                }

                if (count($matches) > 1) {
                    throw new EvaluationException(
                        'Error D3138: The $single() function expected exactly 1 matching result.  Instead it matched more.',
                        'D3138'
                    );
                }

                if ($matches === []) {
                    throw new EvaluationException(
                        'Error D3139: The $single() function expected exactly 1 matching result.  Instead it matched 0.',
                        'D3139'
                    );
                }

                return $matches[0];
            }, '<af?>'),
            $this->builtin('reduce', function (array $arguments) use ($evaluator): mixed {
                $items = $evaluator->toSequence($arguments[0] ?? null);
                $callback = $arguments[1] ?? null;

                if ($items === []) {
                    return $arguments[2] ?? null;
                }

                if ($callback instanceof Closure && $this->support->functionArity($callback) < 2) {
                    throw new EvaluationException(
                        'Error D3050: The second argument of reduce function must have at least two parameters.',
                        'D3050'
                    );
                }

                $hasInitial = array_key_exists(2, $arguments);
                $accumulator = $hasInitial ? $arguments[2] : array_shift($items);

                foreach ($items as $index => $item) {
                    $effectiveIndex = $hasInitial ? $index : $index + 1;
                    $callArguments = [$accumulator, $item];

                    if ($callback instanceof Closure && $this->support->functionArity($callback) >= 3) {
                        $callArguments[] = $effectiveIndex;
                    }

                    if ($callback instanceof Closure && $this->support->functionArity($callback) >= 4) {
                        $callArguments[] = $evaluator->toSequence($arguments[0] ?? null);
                    }

                    $accumulator = $callback($callArguments, $accumulator);
                }

                return $accumulator;
            }, '<afj?:j>'),
            $this->builtin('shuffle', function (array $arguments) use ($evaluator): mixed {
                $items = $evaluator->toSequence($arguments[0] ?? null);
                shuffle($items);

                return $evaluator->collapseSequence($items);
            }, '<a:a>'),
        ];
    }
}
