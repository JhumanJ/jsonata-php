<?php

namespace JsonataPhp\Builtins;

use JsonataPhp\EvaluationException;
use JsonataPhp\Evaluator;

trait RegistersStringBuiltins
{
    /**
     * @param  array<string, mixed>  $rootContext
     * @return array<int, BuiltinDefinition>
     */
    protected function stringBuiltinDefinitions(Evaluator $evaluator, array $rootContext): array
    {
        return [
            $this->builtin('string', fn (array $arguments): string => $evaluator->stringifyPublic($arguments[0] ?? null), '<x-b?:s>'),
            $this->builtin('join', function (array $arguments) use ($evaluator): string {
                $values = array_map(
                    fn (mixed $value): string => $evaluator->stringifyPublic($value),
                    $evaluator->toSequence($arguments[0] ?? null)
                );

                return implode($evaluator->stringifyPublic($arguments[1] ?? ''), $values);
            }, '<a<s>s?:s>'),
            $this->builtin('length', fn (array $arguments): int => mb_strlen($evaluator->stringifyPublic($arguments[0] ?? '')), '<s-:n>'),
            $this->builtin('substring', function (array $arguments) use ($evaluator): string {
                $value = $evaluator->stringifyPublic($arguments[0] ?? '');
                $start = (int) ($arguments[1] ?? 0);
                $length = array_key_exists(2, $arguments) ? (int) $arguments[2] : null;

                return $length === null ? mb_substr($value, $start) : mb_substr($value, $start, $length);
            }, '<s-nn?:s>'),
            $this->builtin('substringBefore', function (array $arguments) use ($evaluator): string {
                $value = $evaluator->stringifyPublic($arguments[0] ?? '');
                $needle = $evaluator->stringifyPublic($arguments[1] ?? '');
                $position = mb_strpos($value, $needle);

                return $position === false ? $value : mb_substr($value, 0, $position);
            }, '<s-s:s>'),
            $this->builtin('substringAfter', function (array $arguments) use ($evaluator): string {
                $value = $evaluator->stringifyPublic($arguments[0] ?? '');
                $needle = $evaluator->stringifyPublic($arguments[1] ?? '');
                $position = mb_strpos($value, $needle);

                return $position === false ? $value : mb_substr($value, $position + mb_strlen($needle));
            }, '<s-s:s>'),
            $this->builtin('lowercase', fn (array $arguments): string => mb_strtolower($evaluator->stringifyPublic($arguments[0] ?? '')), '<s-:s>'),
            $this->builtin('uppercase', fn (array $arguments): string => mb_strtoupper($evaluator->stringifyPublic($arguments[0] ?? '')), '<s-:s>'),
            $this->builtin('trim', fn (array $arguments): string => trim($evaluator->stringifyPublic($arguments[0] ?? '')), '<s-:s>'),
            $this->builtin('pad', function (array $arguments) use ($evaluator): string {
                $value = $evaluator->stringifyPublic($arguments[0] ?? '');
                $width = (int) ($arguments[1] ?? 0);
                $character = $evaluator->stringifyPublic($arguments[2] ?? ' ');
                $padding = max(0, abs($width) - mb_strlen($value));
                $repeat = str_repeat($character === '' ? ' ' : mb_substr($character, 0, 1), $padding);

                return $width < 0 ? $repeat.$value : $value.$repeat;
            }, '<s-ns?:s>'),
            $this->builtin('contains', function (array $arguments) use ($evaluator): bool {
                $value = $evaluator->stringifyPublic($arguments[0] ?? '');
                $search = $arguments[1] ?? '';

                if ($this->isRegexLiteral($search)) {
                    return preg_match($this->toPregPattern($search), $value) === 1;
                }

                $search = $evaluator->stringifyPublic($search);

                return $search === '' || mb_strpos($value, $search) !== false;
            }),
            $this->builtin('split', function (array $arguments) use ($evaluator): array {
                $value = $evaluator->stringifyPublic($arguments[0] ?? '');
                $separator = $arguments[1] ?? '';
                $limit = array_key_exists(2, $arguments) ? (int) $arguments[2] : null;

                if ($limit === 0) {
                    return [];
                }

                if ($limit !== null && $limit < 0) {
                    throw new EvaluationException(
                        'Error D3020: Third argument of split must be a positive number.',
                        'D3020'
                    );
                }

                if ($this->isRegexLiteral($separator)) {
                    return preg_split($this->toPregPattern($separator), $value, $limit ?? -1) ?: [$value];
                }

                $separator = $evaluator->stringifyPublic($separator);

                if ($separator === '') {
                    return preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                }

                return $limit === null ? explode($separator, $value) : explode($separator, $value, $limit);
            }),
            $this->builtin('replace', function (array $arguments) use ($evaluator): string {
                $value = $evaluator->stringifyPublic($arguments[0] ?? '');
                $pattern = $arguments[1] ?? '';
                $replacement = $arguments[2] ?? '';
                $limit = array_key_exists(3, $arguments) ? (int) $arguments[3] : -1;

                if (! $this->isRegexLiteral($pattern) && $evaluator->stringifyPublic($pattern) === '') {
                    throw new EvaluationException(
                        'Error D3010: Second argument of replace function cannot be an empty string.',
                        'D3010'
                    );
                }

                if ($limit === 0) {
                    return $value;
                }

                if ($this->isRegexLiteral($pattern)) {
                    if ($replacement instanceof \Closure) {
                        return $this->replaceWithCallback($value, $pattern, $replacement, $limit);
                    }

                    return preg_replace(
                        $this->toPregPattern($pattern),
                        $evaluator->stringifyPublic($replacement),
                        $value,
                        $limit > 0 ? $limit : -1
                    ) ?? $value;
                }

                $pattern = $evaluator->stringifyPublic($pattern);
                $replacement = $evaluator->stringifyPublic($replacement);

                if ($limit > 0) {
                    $parts = explode($pattern, $value, $limit + 1);

                    return implode($replacement, $parts);
                }

                return str_replace($pattern, $replacement, $value);
            }),
            $this->builtin('match', function (array $arguments) use ($evaluator): mixed {
                $value = $evaluator->stringifyPublic($arguments[0] ?? '');
                $pattern = $arguments[1] ?? null;
                $limit = array_key_exists(2, $arguments) ? (int) $arguments[2] : null;

                if ($limit === 0) {
                    return null;
                }

                if ($limit !== null && $limit < 0) {
                    throw new EvaluationException(
                        'Error D3040: Third argument of match function must be a positive number.',
                        'D3040'
                    );
                }

                if (! $this->isRegexLiteral($pattern)) {
                    throw new EvaluationException(
                        'Error T0412: $match expects a regular expression.',
                        'T0412'
                    );
                }

                preg_match_all($this->toPregPattern($pattern), $value, $matches, PREG_OFFSET_CAPTURE);

                $results = [];
                foreach ($matches[0] as $index => [$match, $offset]) {
                    $groups = [];

                    for ($groupIndex = 1; $groupIndex < count($matches); $groupIndex++) {
                        $groups[] = $matches[$groupIndex][$index][0] ?? '';
                    }

                    $results[] = [
                        'match' => $match,
                        'index' => $offset,
                        'groups' => $groups,
                    ];
                }

                if ($limit === 1) {
                    return $results[0] ?? null;
                }

                if ($limit !== null && $limit > 1) {
                    return array_slice($results, 0, $limit);
                }

                return $evaluator->collapseSequence($results);
            }),
        ];
    }

    private function replaceWithCallback(string $value, mixed $pattern, \Closure $replacement, int $limit): string
    {
        preg_match_all($this->toPregPattern($pattern), $value, $matches, PREG_OFFSET_CAPTURE);

        $result = '';
        $cursor = 0;
        $count = 0;

        foreach ($matches[0] as $index => [$match, $offset]) {
            if ($limit > 0 && $count >= $limit) {
                break;
            }

            $result .= substr($value, $cursor, $offset - $cursor);

            $groups = [];
            for ($groupIndex = 1; $groupIndex < count($matches); $groupIndex++) {
                $groups[] = $matches[$groupIndex][$index][0] ?? '';
            }

            $replacementValue = $replacement([[
                'match' => $match,
                'start' => $offset,
                'end' => $offset + strlen($match),
                'groups' => $groups,
            ]], $match);

            if (! is_string($replacementValue)) {
                throw new EvaluationException(
                    'Error D3012: Attempted to replace a matched string with a non-string value.',
                    'D3012'
                );
            }

            $result .= $replacementValue;
            $cursor = $offset + strlen($match);
            $count++;
        }

        return $result.substr($value, $cursor);
    }
}
