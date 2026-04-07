<?php

namespace JsonataPhp\Builtins;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use JsonataPhp\EvaluationException;
use JsonataPhp\Evaluator;
use JsonataPhp\RegexPattern;
use ReflectionFunction;
use stdClass;
use WeakMap;

class BuiltinSupport
{
    /**
     * @var WeakMap<Closure, int>
     */
    private WeakMap $functionArities;

    public function __construct()
    {
        $this->functionArities = new WeakMap;
    }

    public function registerArity(Closure $function, int $arity): Closure
    {
        $this->functionArities[$function] = $arity;

        return $function;
    }

    /**
     * @param  array<int, mixed>  $arguments
     */
    public function requireCallback(array $arguments, string $functionName, int $index = 1): Closure
    {
        $callback = $arguments[$index] ?? null;

        if ($callback instanceof Closure) {
            return $callback;
        }

        throw new EvaluationException(
            sprintf('Error T0410: %s expects a function callback.', $functionName),
            'T0410'
        );
    }

    public function functionArity(Closure $function): int
    {
        if (isset($this->functionArities[$function])) {
            return $this->functionArities[$function];
        }

        return (new ReflectionFunction($function))->getNumberOfParameters();
    }

    public function isMissingLike(mixed $value, Evaluator $evaluator): bool
    {
        return $evaluator->isMissing($value)
            || ($value instanceof stdClass && get_object_vars($value) === [])
            || (is_object($value) && ! $value instanceof Closure && ! $value instanceof RegexPattern);
    }

    /**
     * @return array<int, mixed>
     */
    public function hofArguments(Closure $function, mixed $value, mixed $position = null, mixed $input = null): array
    {
        $arguments = [$value];
        $arity = $this->functionArity($function);

        if ($arity >= 2) {
            $arguments[] = $position;
        }

        if ($arity >= 3) {
            $arguments[] = $input;
        }

        return $arguments;
    }

    /**
     * @param  array<int, mixed>  $items
     * @return array<int, int|float>
     */
    public function numericSequence(array $items): array
    {
        return array_map(fn (mixed $item): int|float => $this->toNumber($item), $items);
    }

    /**
     * @param  array<int, mixed>  $items
     */
    public function minOrMax(array $items, string $mode): int|float|null
    {
        $values = $this->numericSequence($items);

        if ($values === []) {
            return null;
        }

        return $mode === 'min' ? min($values) : max($values);
    }

    public function toNumber(mixed $value): int|float
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if (is_string($value) && is_numeric($value)) {
            return str_contains($value, '.') ? (float) $value : (int) $value;
        }

        throw new EvaluationException(
            'Error T0412: Expected a numeric value.',
            'T0412'
        );
    }

    public function isRegexLiteral(mixed $value): bool
    {
        return $value instanceof RegexPattern;
    }

    public function toPregPattern(RegexPattern $regex): string
    {
        return $regex->toPcre();
    }

    public function toMillis(string $value): int
    {
        try {
            $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (\Exception) {
            throw new EvaluationException(
                'Error D3110: The timestamp could not be parsed.',
                'D3110'
            );
        }

        return ((int) $date->format('U')) * 1000 + (int) $date->format('v');
    }

    public function fromMillis(int $millis, ?string $picture = null, ?string $timezone = null): string
    {
        $seconds = intdiv($millis, 1000);
        $milliseconds = $millis % 1000;
        $timezone = $timezone === null || $timezone === '' ? 'UTC' : $this->normalizeTimezone($timezone);

        $date = DateTimeImmutable::createFromFormat(
            'U.u',
            sprintf('%d.%06d', $seconds, $milliseconds * 1000),
            new DateTimeZone('UTC')
        );

        if (! $date) {
            throw new EvaluationException(
                'Error D3130: The millis value could not be formatted.',
                'D3130'
            );
        }

        $date = $date->setTimezone(new DateTimeZone($timezone));

        if ($picture === null || $picture === '') {
            return $date->format('Y-m-d\TH:i:s.v\Z');
        }

        return strtr($picture, [
            '[Y0001]' => $date->format('Y'),
            '[M01]' => $date->format('m'),
            '[D01]' => $date->format('d'),
            '[H01]' => $date->format('H'),
            '[h01]' => $date->format('h'),
            '[m01]' => $date->format('i'),
            '[s01]' => $date->format('s'),
            '[f001]' => $date->format('v'),
        ]);
    }

    public function normalizeTimezone(string $timezone): string
    {
        if (preg_match('/^[+-]\d{4}$/', $timezone) === 1) {
            return substr($timezone, 0, 3).':'.substr($timezone, 3, 2);
        }

        return $timezone;
    }

    public function lookupValue(mixed $input, string $key, Evaluator $evaluator): mixed
    {
        if (is_array($input) && array_is_list($input)) {
            $values = [];

            foreach ($input as $item) {
                $value = $this->lookupValue($item, $key, $evaluator);

                if ($value === null || $evaluator->isMissing($value)) {
                    continue;
                }

                if (is_array($value) && array_is_list($value)) {
                    foreach ($value as $nested) {
                        $values[] = $nested;
                    }
                } else {
                    $values[] = $value;
                }
            }

            return $values === [] ? null : $evaluator->collapseSequence($values);
        }

        if (is_array($input) && array_key_exists($key, $input)) {
            return $input[$key];
        }

        return null;
    }

    public function keysOf(mixed $input): mixed
    {
        if (! is_array($input)) {
            return [];
        }

        if (array_is_list($input)) {
            $keys = [];

            foreach ($input as $item) {
                if (! is_array($item)) {
                    continue;
                }

                foreach (array_keys($item) as $key) {
                    $keys[$key] = true;
                }
            }

            $result = array_keys($keys);

            return count($result) === 1 ? $result[0] : $result;
        }

        $result = array_keys($input);

        return count($result) === 1 ? $result[0] : $result;
    }

    /**
     * @param  array<int, mixed>  $items
     * @return array<string, mixed>
     */
    public function mergeObjects(array $items): array
    {
        $merged = [];

        foreach ($items as $item) {
            if (! is_array($item) || array_is_list($item)) {
                throw new EvaluationException(
                    'Error T0412: $merge expects object values.',
                    'T0412'
                );
            }

            foreach ($item as $key => $value) {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }

    /**
     * @param  array<int, mixed>  $items
     */
    public function distinctValues(array $items, Evaluator $evaluator): mixed
    {
        $seen = [];
        $result = [];

        foreach ($items as $item) {
            $hash = is_scalar($item) || $item === null
                ? gettype($item).':'.(string) $item
                : 'json:'.$evaluator->stringifyPublic($item);

            if (array_key_exists($hash, $seen)) {
                continue;
            }

            $seen[$hash] = true;
            $result[] = $item;
        }

        return $evaluator->collapseSequence($result);
    }

    public function padString(string $value, int $width, string $character = ' '): string
    {
        $padLength = abs($width) - mb_strlen($value);

        if ($padLength <= 0) {
            return $value;
        }

        $pad = str_repeat($character, (int) ceil($padLength / max(1, mb_strlen($character))));
        $pad = mb_substr($pad, 0, $padLength);

        return $width >= 0 ? $value.$pad : $pad.$value;
    }

    public function deepClone(mixed $value): mixed
    {
        if ($value instanceof RegexPattern) {
            return new RegexPattern($value->pattern, $value->modifiers);
        }

        if ($value instanceof Closure) {
            return $value;
        }

        if (! is_array($value)) {
            return $value;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($encoded === false) {
            return $value;
        }

        return json_decode($encoded, true);
    }
}
