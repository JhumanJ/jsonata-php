<?php

namespace JsonataPhp\Builtins;

use JsonataPhp\Evaluator;

trait RegistersObjectBuiltins
{
    /**
     * @param  array<string, mixed>  $rootContext
     * @return array<int, BuiltinDefinition>
     */
    protected function objectBuiltinDefinitions(Evaluator $evaluator, array $rootContext): array
    {
        return [
            $this->builtin('lookup', function (array $arguments) use ($evaluator): mixed {
                $input = $arguments[0] ?? null;
                $key = $arguments[1] ?? null;

                if (! is_string($key) && ! is_int($key)) {
                    return null;
                }

                return $this->lookupValue($input, (string) $key, $evaluator);
            }, '<x-s:x>'),
            $this->builtin('keys', fn (array $arguments): mixed => $this->keysOf($arguments[0] ?? null), '<x-:a<s>>'),
            $this->builtin('merge', fn (array $arguments): array => $this->mergeObjects($evaluator->toSequence($arguments[0] ?? null)), '<a<o>:o>'),
            $this->builtin('spread', function (array $arguments): mixed {
                $input = $arguments[0] ?? null;

                if (is_array($input) && array_is_list($input)) {
                    $spread = [];
                    foreach ($input as $item) {
                        $value = $this->spreadValue($item);

                        if (is_array($value) && array_is_list($value)) {
                            $spread = [...$spread, ...$value];

                            continue;
                        }

                        $spread[] = $value;
                    }

                    return $spread;
                }

                return $this->spreadValue($input);
            }, '<x-:x>'),
            $this->builtin('each', function (array $arguments) use ($evaluator): mixed {
                $input = $arguments[0] ?? null;
                $callback = $arguments[1] ?? null;

                if (! is_array($input) || array_is_list($input)) {
                    return null;
                }

                $results = [];
                foreach ($input as $key => $value) {
                    $result = $callback($this->support->hofArguments($callback, $value, $key, $input), $value);
                    if (! $evaluator->isMissing($result)) {
                        $results[] = $result;
                    }
                }

                return $evaluator->collapseSequence($results);
            }, '<o-f:a>'),
            $this->builtin('sift', function (array $arguments): ?array {
                $input = $arguments[0] ?? null;
                $callback = $arguments[1] ?? null;

                if (! is_array($input) || array_is_list($input) || ! $callback instanceof \Closure) {
                    return null;
                }

                $result = [];
                foreach ($input as $key => $value) {
                    if ($callback($this->support->hofArguments($callback, $value, $key, $input), $value)) {
                        $result[$key] = $value;
                    }
                }

                return $result === [] ? null : $result;
            }, '<o-f?:o>'),
            $this->builtin('clone', function (array $arguments): mixed {
                $encoded = json_encode($arguments[0] ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

                return $encoded === false ? null : json_decode($encoded, true);
            }, '<(oa)-:o>'),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function spreadValue(mixed $input): mixed
    {
        if (! is_array($input)) {
            return $input;
        }

        if (array_is_list($input)) {
            $result = [];

            foreach ($input as $item) {
                $value = $this->spreadValue($item);
                if (is_array($value) && array_is_list($value)) {
                    $result = [...$result, ...$value];

                    continue;
                }

                $result[] = $value;
            }

            return $result;
        }

        $spread = [];
        foreach ($input as $key => $value) {
            $spread[] = [$key => $value];
        }

        return $spread;
    }
}
