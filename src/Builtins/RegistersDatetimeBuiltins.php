<?php

namespace JsonataPhp\Builtins;

use JsonataPhp\Evaluator;

trait RegistersDatetimeBuiltins
{
    /**
     * @param  array<string, mixed>  $rootContext
     * @return array<int, BuiltinDefinition>
     */
    protected function datetimeBuiltinDefinitions(Evaluator $evaluator, array $rootContext): array
    {
        return [
            $this->builtin('formatInteger', function (array $arguments): string {
                $value = (int) $this->toNumber($arguments[0] ?? null);
                $picture = (string) ($arguments[1] ?? '');

                return $this->formatInteger($value, $picture);
            }, '<n-s:s>'),
            $this->builtin('parseInteger', function (array $arguments) use ($evaluator): int {
                $value = $evaluator->stringifyPublic($arguments[0] ?? '');
                $picture = (string) ($arguments[1] ?? '');

                return $this->parseInteger($value, $picture);
            }, '<s-s:n>'),
            $this->builtin('toMillis', function (array $arguments) use ($evaluator): ?int {
                $value = $evaluator->stringifyPublic($arguments[0] ?? '');

                return $value === '' ? null : $this->toMillis($value);
            }, '<s-s?:n>'),
            $this->builtin('fromMillis', function (array $arguments): ?string {
                if (! array_key_exists(0, $arguments) || $arguments[0] === null) {
                    return null;
                }

                $millis = (int) $this->toNumber($arguments[0]);
                $picture = array_key_exists(1, $arguments) ? (string) $arguments[1] : null;
                $timezone = array_key_exists(2, $arguments) ? (string) $arguments[2] : null;

                return $this->fromMillis($millis, $picture, $timezone);
            }, '<n-s?s?:s>'),
            $this->builtin('formatInteger', function (array $arguments): string {
                $value = (int) $this->toNumber($arguments[0] ?? null);
                $picture = (string) ($arguments[1] ?? '');

                return $this->integerFormatter->format($value, $picture);
            }, '<n-s:s>'),
            $this->builtin('parseInteger', function (array $arguments) use ($evaluator): int {
                $value = $evaluator->stringifyPublic($arguments[0] ?? '');
                $picture = (string) ($arguments[1] ?? '');

                return $this->integerFormatter->parse($value, $picture);
            }, '<s-s:n>'),
            $this->builtin('now', fn (): string => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z')),
            $this->builtin('millis', fn (): int => (int) floor(microtime(true) * 1000)),
        ];
    }
}
