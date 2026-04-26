<?php

namespace JsonataPhp\Builtins;

use Closure;
use JsonataPhp\EvaluationException;
use JsonataPhp\Evaluator;

trait RegistersStringBuiltins
{
    /**
     * @return array<int, BuiltinDefinition>
     */
    protected function stringBuiltinDefinitions(Evaluator $evaluator, mixed $rootContext): array
    {
        return [
            $this->builtin('string', fn (array $arguments): string => $evaluator->stringifyPublic(
                $arguments[0] ?? null,
                (bool) ($arguments[1] ?? false)
            ), '<x-b?:s>'),
            $this->builtin('join', function (array $arguments) use ($evaluator): ?string {
                if (! array_key_exists(0, $arguments) || $arguments[0] === null) {
                    return null;
                }

                $values = array_map(
                    fn (mixed $value): string => $evaluator->stringifyPublic($value),
                    $evaluator->toSequence($arguments[0])
                );

                return implode($evaluator->stringifyPublic($arguments[1] ?? ''), $values);
            }, '<a<s>s?:s>'),
            $this->builtin('length', fn (array $arguments): int => count($this->stringToArray($evaluator->stringifyPublic($arguments[0] ?? ''))), '<s-:n>'),
            $this->builtin('substring', function (array $arguments) use ($evaluator): string {
                $value = $evaluator->stringifyPublic($arguments[0] ?? '');
                $start = (int) ($arguments[1] ?? 0);
                $length = array_key_exists(2, $arguments) ? (int) $arguments[2] : null;
                $characters = $this->stringToArray($value);
                $characterCount = count($characters);

                if ($characterCount + $start < 0) {
                    $start = 0;
                }

                $startIndex = $start >= 0 ? $start : max(0, $characterCount + $start);

                if ($length !== null) {
                    if ($length <= 0) {
                        return '';
                    }

                    $endIndex = $start >= 0
                        ? $start + $length
                        : max(0, $characterCount + $start + $length);

                    return implode('', array_slice($characters, $startIndex, max(0, $endIndex - $startIndex)));
                }

                return implode('', array_slice($characters, $startIndex));
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
            $this->builtin('trim', function (array $arguments) use ($evaluator): string {
                $value = $evaluator->stringifyPublic($arguments[0] ?? '');
                $value = preg_replace('/[ \t\n\r]+/u', ' ', $value) ?? $value;

                return trim($value, ' ');
            }, '<s-:s>'),
            $this->builtin('pad', function (array $arguments) use ($evaluator): string {
                $value = $evaluator->stringifyPublic($arguments[0] ?? '');
                $width = (int) ($arguments[1] ?? 0);
                $character = $evaluator->stringifyPublic($arguments[2] ?? ' ');
                $character = $character === '' ? ' ' : $character;

                return $this->support->padString($value, $width, $character);
            }, '<s-ns?:s>'),
            $this->builtin('contains', function (array $arguments) use ($evaluator): bool {
                $value = $evaluator->stringifyPublic($arguments[0] ?? '');
                $search = $arguments[1] ?? '';

                if ($this->isRegexLiteral($search)) {
                    return preg_match($this->toPregPattern($search), $value) === 1;
                }

                if (! is_string($search)) {
                    throw new EvaluationException(
                        'Error T0410: Argument 2 does not match function signature <s-(sf):b>.',
                        'T0410',
                        0,
                        ['index' => 2, 'value' => $search]
                    );
                }

                $search = $evaluator->stringifyPublic($search);

                return $search === '' || mb_strpos($value, $search) !== false;
            }),
            $this->builtin('split', function (array $arguments) use ($evaluator): array {
                $value = $evaluator->stringifyPublic($arguments[0] ?? '');
                $separator = $arguments[1] ?? '';
                $limit = array_key_exists(2, $arguments) ? (int) floor((float) $arguments[2]) : null;

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
                    $parts = preg_split($this->toPregPattern($separator), $value) ?: [$value];

                    return $limit === null ? $parts : array_slice($parts, 0, $limit);
                }

                if ($separator instanceof Closure) {
                    return $this->splitWithMatcher($value, $separator, $limit);
                }

                $separator = $evaluator->stringifyPublic($separator);

                if ($separator === '') {
                    $parts = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];

                    return $limit === null ? $parts : array_slice($parts, 0, $limit);
                }

                $parts = explode($separator, $value);

                return $limit === null ? $parts : array_slice($parts, 0, $limit);
            }, '<s-(sf)n?:a<s>>'),
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

                if (array_key_exists(3, $arguments) && $limit < 0) {
                    throw new EvaluationException(
                        'Error D3011: Fourth argument of replace function must evaluate to a positive number.',
                        'D3011'
                    );
                }

                if ($this->isRegexLiteral($pattern)) {
                    if ($replacement instanceof Closure) {
                        return $this->replaceWithCallback($value, $pattern, $replacement, $limit);
                    }

                    return $this->replaceWithRegexString(
                        $value,
                        $pattern,
                        $evaluator->stringifyPublic($replacement),
                        $limit
                    );
                }

                $pattern = $evaluator->stringifyPublic($pattern);
                $replacement = $evaluator->stringifyPublic($replacement);

                if ($limit > 0) {
                    $parts = explode($pattern, $value, $limit + 1);

                    return implode($replacement, $parts);
                }

                return str_replace($pattern, $replacement, $value);
            }, '<s-(sf)(sf)n?:s>'),
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

                if ($pattern instanceof Closure) {
                    $results = $this->matchWithMatcher($value, $pattern, $limit);

                    return match (true) {
                        $limit === 1 => $results[0] ?? null,
                        $results === [] => null,
                        default => $evaluator->collapseSequence($results),
                    };
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

    private function replaceWithRegexString(string $value, mixed $pattern, string $replacement, int $limit): string
    {
        preg_match_all($this->toPregPattern($pattern), $value, $matches, PREG_OFFSET_CAPTURE);

        $result = '';
        $cursor = 0;
        $count = 0;

        foreach ($matches[0] as $index => [$match, $offset]) {
            if ($limit > 0 && $count >= $limit) {
                break;
            }

            $this->assertNonEmptyRegexMatch($match);

            $result .= substr($value, $cursor, $offset - $cursor);
            $result .= $this->expandReplacementTemplate($replacement, $this->replacementGroups($matches, $index));
            $cursor = $offset + strlen($match);
            $count++;
        }

        return $result.substr($value, $cursor);
    }

    private function replaceWithCallback(string $value, mixed $pattern, Closure $replacement, int $limit): string
    {
        preg_match_all($this->toPregPattern($pattern), $value, $matches, PREG_OFFSET_CAPTURE);

        $result = '';
        $cursor = 0;
        $count = 0;

        foreach ($matches[0] as $index => [$match, $offset]) {
            if ($limit > 0 && $count >= $limit) {
                break;
            }

            $this->assertNonEmptyRegexMatch($match);

            $result .= substr($value, $cursor, $offset - $cursor);

            $replacementValue = $replacement([[
                'match' => $match,
                'start' => $offset,
                'end' => $offset + strlen($match),
                'groups' => array_slice($this->replacementGroups($matches, $index), 1),
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

    /**
     * @param  array<int, array<int, array{0: string|null, 1: int}>>  $matches
     * @return array<int, string>
     */
    private function replacementGroups(array $matches, int $index): array
    {
        $groups = [];

        foreach ($matches as $groupIndex => $groupMatches) {
            $groups[$groupIndex] = (string) ($groupMatches[$index][0] ?? '');
        }

        return $groups;
    }

    /**
     * @param  array<int, string>  $groups
     */
    private function expandReplacementTemplate(string $replacement, array $groups): string
    {
        $result = '';
        $length = strlen($replacement);

        for ($index = 0; $index < $length; $index++) {
            $character = $replacement[$index];

            if ($character !== '$' || $index + 1 >= $length) {
                $result .= $character;

                continue;
            }

            $next = $replacement[$index + 1];

            if ($next === '$') {
                $result .= '$';
                $index++;

                continue;
            }

            if (! ctype_digit($next)) {
                $result .= '$';

                continue;
            }

            if ($next === '0') {
                $result .= $groups[0] ?? '';
                $index++;

                continue;
            }

            $groupIndex = (int) $next;

            if (array_key_exists($groupIndex, $groups)) {
                $twoDigitIndex = null;
                if ($index + 2 < $length && ctype_digit($replacement[$index + 2])) {
                    $twoDigitIndex = (int) ($next.$replacement[$index + 2]);
                }

                if ($twoDigitIndex !== null && array_key_exists($twoDigitIndex, $groups)) {
                    $result .= $groups[$twoDigitIndex];
                    $index += 2;

                    continue;
                }

                $result .= $groups[$groupIndex];
                $index++;

                continue;
            }

            $index++;
        }

        return $result;
    }

    private function assertNonEmptyRegexMatch(string $match): void
    {
        if ($match !== '') {
            return;
        }

        throw new EvaluationException(
            'Error D1004: Regular expression matches zero length string',
            'D1004'
        );
    }

    /**
     * @return array<int, string>
     */
    private function splitWithMatcher(string $value, Closure $matcher, ?int $limit): array
    {
        $parts = [];
        $cursor = 0;

        foreach ($this->matcherResults($value, $matcher, null) as $match) {
            if ($limit !== null && count($parts) >= $limit) {
                return array_slice($parts, 0, $limit);
            }

            $start = (int) $match['start'];
            $end = (int) $match['end'];
            $parts[] = mb_substr($value, $cursor, $start - $cursor);
            $cursor = $end;
        }

        if ($limit === null || count($parts) < $limit) {
            $parts[] = mb_substr($value, $cursor);
        }

        return $limit === null ? $parts : array_slice($parts, 0, $limit);
    }

    /**
     * @return array<int, array{match: string, index: int, groups: array<int, mixed>}>
     */
    private function matchWithMatcher(string $value, Closure $matcher, ?int $limit): array
    {
        $results = [];

        foreach ($this->matcherResults($value, $matcher, $limit) as $match) {
            $results[] = [
                'match' => (string) $match['match'],
                'index' => (int) $match['start'],
                'groups' => $match['groups'],
            ];
        }

        return $results;
    }

    /**
     * @return array<int, array{match: string, start: int, end: int, groups: array<int, mixed>}>
     */
    private function matcherResults(string $value, Closure $matcher, ?int $limit): array
    {
        $results = [];
        $next = $matcher;
        $arguments = [$value];

        while ($next instanceof Closure && ($limit === null || count($results) < $limit)) {
            $match = $next($arguments, $value);

            if ($match === null || ($match instanceof \stdClass && get_object_vars($match) === [])) {
                break;
            }

            $match = $this->assertMatcherResult($match);
            $results[] = [
                'match' => (string) $match['match'],
                'start' => (int) $match['start'],
                'end' => (int) $match['end'],
                'groups' => $match['groups'],
            ];

            $next = $match['next'] ?? null;
            $arguments = [];
        }

        return $results;
    }

    /**
     * @return array{match: string, start: int|float, end: int|float, groups: array<int, mixed>, next?: Closure}
     */
    private function assertMatcherResult(mixed $match): array
    {
        if (! is_array($match)
            || ! array_key_exists('match', $match)
            || ! is_string($match['match'])
            || ! array_key_exists('start', $match)
            || ! (is_int($match['start']) || is_float($match['start']))
            || ! array_key_exists('end', $match)
            || ! (is_int($match['end']) || is_float($match['end']))
            || ! array_key_exists('groups', $match)
            || ! is_array($match['groups'])
            || (array_key_exists('next', $match) && ! $match['next'] instanceof Closure)
        ) {
            throw new EvaluationException(
                'Error T1010: The matcher function argument passed to function "split" does not return the correct object structure',
                'T1010'
            );
        }

        if ($match['end'] < $match['start']) {
            throw new EvaluationException(
                'Error T1010: The matcher function argument passed to function "split" does not return the correct object structure',
                'T1010'
            );
        }

        return $match;
    }

    /**
     * @return array<int, string>
     */
    private function stringToArray(string $value): array
    {
        return preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
