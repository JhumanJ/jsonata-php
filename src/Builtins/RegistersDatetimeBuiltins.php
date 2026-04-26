<?php

namespace JsonataPhp\Builtins;

use JsonataPhp\Evaluator;

trait RegistersDatetimeBuiltins
{
    /**
     * @return array<int, BuiltinDefinition>
     */
    protected function datetimeBuiltinDefinitions(Evaluator $evaluator, mixed $rootContext): array
    {
        return [
            $this->builtin('toMillis', function (array $arguments) use ($evaluator): ?int {
                $value = $evaluator->stringifyPublic($arguments[0] ?? '');
                $picture = array_key_exists(1, $arguments) ? (string) $arguments[1] : null;

                return $value === '' ? null : $this->toMillis($value, $picture);
            }, '<s-s?:n>'),
            $this->builtin('fromMillis', function (array $arguments) use ($evaluator): mixed {
                if (! array_key_exists(0, $arguments) || $arguments[0] === null || $evaluator->isMissing($arguments[0])) {
                    return $evaluator->missingValuePublic();
                }

                $millis = (int) $this->toNumber($arguments[0]);
                $picture = array_key_exists(1, $arguments) && ! $evaluator->isMissing($arguments[1]) ? (string) $arguments[1] : null;
                $timezone = array_key_exists(2, $arguments) && ! $evaluator->isMissing($arguments[2]) ? (string) $arguments[2] : null;
                $timezone = $timezone === '0000' ? '+0000' : $timezone;

                return $this->fromMillis($millis, $picture, $timezone);
            }, '<n-s?s?:s>'),
            $this->builtin('formatInteger', function (array $arguments): ?string {
                if (! array_key_exists(0, $arguments) || $arguments[0] === null) {
                    return null;
                }

                $value = $this->toNumber($arguments[0]);
                $picture = (string) ($arguments[1] ?? '');

                return $this->integerFormatter->format($value, $picture);
            }, '<n-s:s>'),
            $this->builtin('parseInteger', function (array $arguments) use ($evaluator): int|float|null {
                if (! array_key_exists(0, $arguments) || $arguments[0] === null) {
                    return null;
                }

                $value = $evaluator->stringifyPublic($arguments[0] ?? '');
                $picture = (string) ($arguments[1] ?? '');

                return $this->integerFormatter->parse($value, $picture);
            }, '<s-s:n>'),
            $this->builtin('now', fn (): string => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z')),
            $this->builtin('millis', fn (): int => (int) floor(microtime(true) * 1000)),
        ];
    }
}
