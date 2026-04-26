<?php

namespace JsonataPhp;

use Closure;
use JsonataPhp\Builtins\Signature;
use stdClass;

class Evaluator
{
    private object $missingValue;

    public function __construct(
        private readonly Functions $functions,
    ) {
        $this->missingValue = new stdClass;
    }

    /**
     * @param  array<string, mixed>  $ast
     * @param  array<string, mixed>  $bindings
     */
    public function evaluate(array $ast, mixed $rootContext, array $bindings = []): mixed
    {
        return $this->evaluateWithContext($ast, $rootContext, $rootContext, $bindings);
    }

    /**
     * @param  array<string, mixed>  $ast
     * @param  array<string, mixed>  $bindings
     */
    public function evaluateWithContext(array $ast, mixed $context, mixed $rootContext, array $bindings = []): mixed
    {
        $environment = [
            ...$this->functions->defaultEnvironment($this, $rootContext),
            ...$this->normalizeBindings($bindings),
        ];
        $result = $this->evaluateAst($ast, $context, $environment, $rootContext);

        return $this->isMissing($result) ? null : $this->unwrapTuples($result);
    }

    /**
     * @param  array<string, mixed>  $bindings
     * @return array<string, mixed>
     */
    private function normalizeBindings(array $bindings): array
    {
        $environment = [];

        foreach ($bindings as $name => $value) {
            $environment[str_starts_with($name, '$') ? $name : '$'.$name] = $value;
        }

        return $environment;
    }

    public function isMissing(mixed $value): bool
    {
        return $value === $this->missingValue;
    }

    public function missingValuePublic(): mixed
    {
        return $this->missingValue;
    }

    public function normalizeValuePublic(mixed $value): mixed
    {
        if ($this->isMissing($value)) {
            return null;
        }

        return $this->unwrapTuples($value);
    }

    public function normalizePreservingMissingPublic(mixed $value): mixed
    {
        if ($this->isMissing($value)) {
            return $value;
        }

        if ($value instanceof stdClass && get_object_vars($value) === []) {
            return $value;
        }

        return $this->unwrapTuples($value);
    }

    /**
     * @return array<int, mixed>
     */
    public function toSequence(mixed $value): array
    {
        if ($this->isMissing($value) || $value === null) {
            return [];
        }

        if (is_array($value) && array_is_list($value)) {
            return $value;
        }

        return [$value];
    }

    /**
     * @param  array<int, mixed>  $items
     */
    public function collapseSequence(array $items): mixed
    {
        $items = array_values($items);

        return match (count($items)) {
            0 => $this->missingValue,
            1 => $items[0],
            default => $items,
        };
    }

    /**
     * @param  array<string, mixed>  $ast
     * @param  array<string, mixed>  $environment
     */
    private function evaluateAst(array $ast, mixed $context, array &$environment, mixed $rootContext): mixed
    {
        return match ($ast['type']) {
            'literal' => $this->normalizeLiteral($ast['value']),
            'identifier' => $this->resolveIdentifier((string) $ast['name'], $context),
            'variable' => $this->resolveVariable((string) $ast['name'], $context, $environment, $rootContext),
            'bind' => $this->evaluateBind($ast, $context, $environment, $rootContext),
            'sequence' => $this->evaluateSequence($ast, $context, $environment, $rootContext),
            'assignment' => $this->evaluateAssignment($ast, $context, $environment, $rootContext),
            'grouping' => $this->evaluateGrouping($ast, $context, $environment, $rootContext),
            'conditional' => $this->evaluateConditional($ast, $context, $environment, $rootContext),
            'unary' => $this->evaluateUnary($ast, $context, $environment, $rootContext),
            'binary' => $this->evaluateBinary($ast, $context, $environment, $rootContext),
            'property' => $this->evaluateProperty($ast, $context, $environment, $rootContext),
            'path_step' => $this->evaluatePathStep($ast, $context, $environment, $rootContext),
            'wildcard' => $this->evaluateWildcard(
                $this->evaluateAst($ast['target'], $context, $environment, $rootContext)
            ),
            'wildcard_context' => $this->evaluateWildcard($context),
            'descendant' => $this->evaluateDescendant(
                $this->evaluateAst($ast['target'], $context, $environment, $rootContext)
            ),
            'descendant_context' => $this->evaluateDescendant($context),
            'parent_context' => $this->evaluateParentContext($ast, $context),
            'parent' => $this->evaluateParent($ast, $context, $environment, $rootContext),
            'array_constructor' => $this->evaluateArrayConstructor($ast, $context, $environment, $rootContext),
            'subscript' => $this->evaluateSubscript($ast, $context, $environment, $rootContext),
            'filter' => $this->filterSequence($ast, $context, $environment, $rootContext),
            'sort' => $this->evaluateSort($ast, $context, $environment, $rootContext),
            'object_map' => $this->evaluateObjectMap($ast, $context, $environment, $rootContext),
            'group' => $this->evaluateGroup($ast, $context, $environment, $rootContext),
            'array' => $this->evaluateArrayLiteral($ast, $context, $environment, $rootContext),
            'object' => $this->evaluateObjectLiteral($ast, $context, $environment, $rootContext),
            'function' => $this->createClosure($ast, $context, $environment, $rootContext),
            'transform' => $this->createTransformClosure($ast, $environment, $rootContext),
            'call' => $this->evaluateCall($ast, $context, $environment, $rootContext),
            'partial' => $this->evaluatePartial($ast, $context, $environment, $rootContext),
            'placeholder' => throw new EvaluationException(
                'Error T1007: Placeholder values are only valid inside function calls.',
                'T1007'
            ),
            default => throw new EvaluationException(
                sprintf('Unsupported JSONata AST node [%s].', (string) $ast['type'])
            ),
        };
    }

    private function normalizeLiteral(mixed $value): mixed
    {
        if (
            is_array($value)
            && array_key_exists('pattern', $value)
            && array_key_exists('modifiers', $value)
        ) {
            return new RegexPattern((string) $value['pattern'], (string) $value['modifiers']);
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $ast
     * @param  array<string, mixed>  $environment
     */
    private function evaluateSequence(array $ast, mixed $context, array &$environment, mixed $rootContext): mixed
    {
        $result = $this->missingValue;

        foreach ($ast['expressions'] as $expression) {
            $result = $this->evaluateAst($expression, $context, $environment, $rootContext);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $ast
     * @param  array<string, mixed>  $environment
     */
    private function evaluateGrouping(array $ast, mixed $context, array &$environment, mixed $rootContext): mixed
    {
        $localEnvironment = $environment;

        return $this->evaluateAst($ast['expression'], $context, $localEnvironment, $rootContext);
    }

    /**
     * @param  array<string, mixed>  $ast
     * @param  array<string, mixed>  $environment
     */
    private function evaluateAssignment(array $ast, mixed $context, array &$environment, mixed $rootContext): mixed
    {
        if (($ast['target']['type'] ?? null) !== 'variable') {
            throw new EvaluationException(
                'Error S0212: Left-hand side of := must be a variable.',
                'S0212'
            );
        }

        $value = $this->evaluateAst($ast['value'], $context, $environment, $rootContext);
        $environment[(string) $ast['target']['name']] = $value;

        return $value;
    }

    /**
     * @param  array<string, mixed>  $environment
     */
    private function resolveVariable(string $name, mixed $context, array $environment, mixed $rootContext): mixed
    {
        if ($this->isTuple($context)) {
            $binding = $this->tupleBindings($context)[$name] ?? null;
            if ($binding !== null || array_key_exists($name, $this->tupleBindings($context))) {
                return $binding;
            }
        }

        return match ($name) {
            '$' => $this->tupleValue($context),
            '$$' => $this->wrapTupleResult($rootContext, $this->tupleBindings($context)),
            default => array_key_exists($name, $environment) ? $environment[$name] : $this->missingValue,
        };
    }

    /**
     * @param  array<string, mixed>  $ast
     * @param  array<string, mixed>  $environment
     */
    private function evaluateConditional(array $ast, mixed $context, array &$environment, mixed $rootContext): mixed
    {
        $test = $this->evaluateAst($ast['test'], $context, $environment, $rootContext);

        if ($this->isTruthy($test)) {
            return $this->evaluateAst($ast['consequent'], $context, $environment, $rootContext);
        }

        if (($ast['alternate'] ?? null) === null) {
            return $this->missingValue;
        }

        return $this->evaluateAst($ast['alternate'], $context, $environment, $rootContext);
    }

    /**
     * @param  array<string, mixed>  $ast
     * @param  array<string, mixed>  $environment
     */
    private function evaluateUnary(array $ast, mixed $context, array &$environment, mixed $rootContext): mixed
    {
        $value = $this->evaluateAst($ast['argument'], $context, $environment, $rootContext);

        if ($this->isMissing($value) || $value === null) {
            return $this->missingValue;
        }

        $value = $this->normalizeValuePublic($value);

        if (! is_int($value) && ! is_float($value)) {
            throw new EvaluationException(
                sprintf('Error D1002: Cannot negate a non-numeric value: "%s"', $this->stringify($value)),
                'D1002',
                (int) ($ast['position'] ?? 0),
                ['token' => (string) $ast['operator'], 'position' => (int) ($ast['position'] ?? 0)]
            );
        }

        return match ($ast['operator']) {
            '+' => $value,
            '-' => -$value,
            default => throw new EvaluationException(
                sprintf('Unsupported JSONata unary operator [%s].', (string) $ast['operator'])
            ),
        };
    }

    /**
     * @param  array<string, mixed>  $ast
     * @param  array<string, mixed>  $environment
     */
    private function evaluateBinary(array $ast, mixed $context, array &$environment, mixed $rootContext): mixed
    {
        if ($ast['operator'] === '~>' && $this->isRangeCountChain($ast)) {
            return $this->evaluateRangeCountChain($ast['left']['items'][0], $context, $environment, $rootContext);
        }

        $left = $this->evaluateAst($ast['left'], $context, $environment, $rootContext);

        if ($ast['operator'] === '~>') {
            return $this->evaluateChain($ast['right'], $left, $context, $environment, $rootContext);
        }

        if ($ast['operator'] === 'and') {
            return $this->isTruthy($left) && $this->isTruthy($this->evaluateAst($ast['right'], $context, $environment, $rootContext));
        }

        if ($ast['operator'] === 'or') {
            return $this->isTruthy($left) || $this->isTruthy($this->evaluateAst($ast['right'], $context, $environment, $rootContext));
        }

        $right = $this->evaluateAst($ast['right'], $context, $environment, $rootContext);

        return match ($ast['operator']) {
            '=' => $this->compareValues($left, $right),
            '!=' => ! $this->compareValues($left, $right),
            '<' => $this->compareNumbers($left, $right, '<'),
            '<=' => $this->compareNumbers($left, $right, '<='),
            '>' => $this->compareNumbers($left, $right, '>'),
            '>=' => $this->compareNumbers($left, $right, '>='),
            'in' => $this->inSequence($left, $right),
            '??' => ! $this->isMissing($left) ? $left : $right,
            '?:' => $this->isTruthy($left) ? $left : $right,
            '+' => $this->evaluateNumericBinary($left, $right, '+'),
            '-' => $this->evaluateNumericBinary($left, $right, '-'),
            '*' => $this->evaluateNumericBinary($left, $right, '*'),
            '**' => $this->evaluateNumericBinary($left, $right, '**'),
            '/' => $this->evaluateNumericBinary($left, $right, '/'),
            '%' => $this->evaluateNumericBinary($left, $right, '%'),
            '&' => $this->stringify($left).$this->stringify($right),
            '..' => $this->buildRange($left, $right),
            default => throw new EvaluationException(
                sprintf('Unsupported JSONata operator [%s].', (string) $ast['operator'])
            ),
        };
    }

    /**
     * @param  array<string, mixed>  $ast
     * @param  array<string, mixed>  $environment
     * @return array<int, mixed>
     */
    private function evaluateArrayLiteral(array $ast, mixed $context, array &$environment, mixed $rootContext): array
    {
        $items = [];

        foreach ($ast['items'] as $item) {
            $value = $this->evaluateAst($item, $context, $environment, $rootContext);

            if ($this->isMissing($value)) {
                continue;
            }

            $normalizedValue = $this->tupleValue($value);

            if (
                is_array($normalizedValue)
                && array_is_list($normalizedValue)
                && ($item['type'] ?? null) !== 'array'
                && ($item['type'] ?? null) !== 'object'
            ) {
                foreach ($normalizedValue as $nestedValue) {
                    $items[] = $this->tupleValue($nestedValue);
                }

                continue;
            }

            if (
                is_array($value)
                && array_is_list($value)
                && ($item['type'] ?? null) !== 'array'
                && ($item['type'] ?? null) !== 'object'
            ) {
                foreach ($value as $nestedValue) {
                    $items[] = $this->tupleValue($nestedValue);
                }

                continue;
            }

            $items[] = $this->tupleValue($value);
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $ast
     * @param  array<string, mixed>  $environment
     * @return array<int, mixed>
     */
    private function evaluateArrayConstructor(array $ast, mixed $context, array &$environment, mixed $rootContext): array
    {
        if (($ast['target']['type'] ?? null) === 'identifier') {
            return $this->arrayConstructorFromValue(
                $this->accessProperty($context, (string) $ast['target']['name'], true)
            );
        }

        return $this->arrayConstructorFromValue(
            $this->evaluateAst($ast['target'], $context, $environment, $rootContext)
        );
    }

    /**
     * @return array<int, mixed>
     */
    private function arrayConstructorFromValue(mixed $value): array
    {
        if ($this->isMissing($value) || $value === null) {
            return [];
        }

        if ($this->isTuple($value) && (! is_array($this->tupleValue($value)) || ! array_is_list($this->tupleValue($value)))) {
            return [$value];
        }

        $value = $this->tupleValue($value);

        return is_array($value) && array_is_list($value)
            ? array_values($value)
            : [$value];
    }

    private function resolveIdentifier(string $name, mixed $context): mixed
    {
        return $this->accessProperty($context, $name, true);
    }

    /**
     * @param  array<string, mixed>  $ast
     * @param  array<string, mixed>  $environment
     */
    private function evaluateProperty(array $ast, mixed $context, array &$environment, mixed $rootContext): mixed
    {
        $value = $this->accessProperty(
            $this->evaluateAst($ast['target'], $context, $environment, $rootContext),
            (string) $ast['name'],
            in_array($ast['target']['type'] ?? null, ['array_constructor', 'object', 'subscript', 'variable'], true)
        );

        if (! $this->isMissing($value)
            && $this->hasArrayConstructorRoot($ast['target'])
            && (! is_array($value) || ! array_is_list($value))) {
            return [$value];
        }

        return $value;
    }

    private function accessProperty(mixed $target, string $name, bool $preserveDirectArray = false): mixed
    {
        if ($this->isMissing($target) || $target === null) {
            return $this->missingValue;
        }

        if ($this->isTuple($target)) {
            $direct = $this->accessProperty($this->tupleValue($target), $name, $preserveDirectArray);

            if (! $this->isMissing($direct)) {
                return $this->wrapTupleResult(
                    $direct,
                    $this->tupleBindings($target),
                    is_array($this->tupleValue($target)) && array_is_list($this->tupleValue($target)) ? null : $target
                );
            }

            $lookupParent = $this->tupleLookupParent($target);

            if ($lookupParent !== null) {
                return $this->wrapTupleResult(
                    $this->accessProperty($lookupParent, $name, $preserveDirectArray),
                    $this->tupleBindings($target),
                    $lookupParent,
                    $lookupParent
                );
            }

            return $this->missingValue;
        }

        if ($name === '$') {
            return $this->tupleValue($target);
        }

        if (is_array($target) && array_is_list($target)) {
            $projected = [];

            foreach ($target as $item) {
                $value = $this->accessProperty($item, $name);
                if (! $this->isMissing($value)) {
                    if (is_array($value) && array_is_list($value)) {
                        foreach ($value as $nestedValue) {
                            $projected[] = $this->wrapTupleResult($nestedValue, []);
                        }
                    } else {
                        $projected[] = $this->wrapTupleResult($value, []);
                    }
                }
            }

            return $this->collapseSequence($projected);
        }

        if ($preserveDirectArray && is_array($target) && array_key_exists($name, $target)) {
            return $this->makeTuple($target[$name], [], $target);
        }

        if (is_array($target) && array_key_exists($name, $target)) {
            return $this->wrapTupleResult($target[$name], [], $target);
        }

        return $this->missingValue;
    }

    /**
     * @param  array<string, mixed>  $ast
     * @param  array<string, mixed>  $environment
     */
    private function evaluateSubscript(array $ast, mixed $context, array &$environment, mixed $rootContext): mixed
    {
        $value = $this->accessSubscript(
            $this->evaluateAst($ast['target'], $context, $environment, $rootContext),
            $this->evaluateAst($ast['index'], $context, $environment, $rootContext),
            ! in_array($ast['target']['type'] ?? null, ['grouping', 'identifier'], true)
        );

        return $this->hasArrayConstructorRoot($ast['target'])
            ? $this->arrayConstructorFromValue($value)
            : $value;
    }

    private function accessSubscript(mixed $target, mixed $index, bool $allowParentDistribution = true): mixed
    {
        if ($this->isMissing($target) || $target === null) {
            return $this->missingValue;
        }

        if ($this->isTuple($target)) {
            return $this->wrapTupleResult(
                $this->accessSubscript($this->tupleValue($target), $index, $allowParentDistribution),
                $this->tupleBindings($target),
                $target
            );
        }

        if ($this->isNumericSelector($index) && is_array($target) && array_is_list($target)) {
            if ($allowParentDistribution && $this->isParentTrackedSequence($target)) {
                return $this->selectFromParentTrackedSequence($target, $this->normalizeNumericSelector($index));
            }

            $resolvedIndex = $this->normalizeNumericSelector($index);
            $resolvedIndex = $resolvedIndex < 0 ? count($target) + $resolvedIndex : $resolvedIndex;

            return array_key_exists($resolvedIndex, $target)
                ? $this->wrapTupleResult($target[$resolvedIndex], [])
                : $this->missingValue;
        }

        // JSONata allows indexing into a singleton sequence after a path step
        // has already collapsed a one-item array into its only value.
        if ($this->isNumericSelector($index)) {
            $resolvedIndex = $this->normalizeNumericSelector($index);

            return $resolvedIndex === 0 || $resolvedIndex === -1
                ? $this->wrapTupleResult($target, [])
                : $this->missingValue;
        }

        if (is_array($index) && array_is_list($index) && is_array($target) && array_is_list($target)) {
            $selectors = $this->flattenSubscriptIndexes($index);
            $hasNumericSelector = false;
            $hasNonNumericSelector = false;
            $numericIndexes = [];

            foreach ($selectors as $selector) {
                if ($this->isNumericSelector($selector)) {
                    $hasNumericSelector = true;
                    $resolvedIndex = $this->normalizeNumericSelector($selector);
                    $numericIndexes[] = $resolvedIndex < 0 ? count($target) + $resolvedIndex : $resolvedIndex;

                    continue;
                }

                $hasNonNumericSelector = true;
            }

            if ($hasNumericSelector && $hasNonNumericSelector) {
                return $this->wrapTupleResult($target, []);
            }

            if (! $hasNumericSelector) {
                return $this->missingValue;
            }

            $selected = [];
            $selectedIndexes = array_values(array_unique(array_filter(
                $numericIndexes,
                static fn (mixed $candidate): bool => is_int($candidate) && $candidate >= 0
            )));

            if ($allowParentDistribution && $this->isParentTrackedSequence($target)) {
                foreach ($selectedIndexes as $selectedIndex) {
                    $value = $this->selectFromParentTrackedSequence($target, $selectedIndex);

                    if (! $this->isMissing($value)) {
                        $selected[] = $value;
                    }
                }

                return $this->collapseSequence($selected);
            }

            foreach ($target as $position => $item) {
                if (in_array($position, $selectedIndexes, true)) {
                    $selected[] = $this->wrapTupleResult($item, []);
                }
            }

            return $this->collapseSequence($selected);
        }

        if (is_string($index) && is_array($target) && array_key_exists($index, $target)) {
            return $this->wrapTupleResult($target[$index], []);
        }

        return $this->missingValue;
    }

    private function isNumericSelector(mixed $value): bool
    {
        return is_int($value) || is_float($value);
    }

    private function normalizeNumericSelector(int|float $value): int
    {
        return (int) $value;
    }

    /**
     * @param  array<int, mixed>  $target
     */
    private function isParentTrackedSequence(array $target): bool
    {
        foreach ($target as $item) {
            if ($this->isTuple($item) && $this->tupleParent($item) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, mixed>  $target
     */
    private function selectFromParentTrackedSequence(array $target, int $index): mixed
    {
        $selected = [];

        foreach ($this->groupParentTrackedSequence($target) as $group) {
            $resolvedIndex = $index < 0 ? count($group) + $index : $index;

            if (array_key_exists($resolvedIndex, $group)) {
                $selected[] = $group[$resolvedIndex];
            }
        }

        return $this->collapseSequence($selected);
    }

    /**
     * @param  array<int, mixed>  $target
     * @return array<int, array<int, mixed>>
     */
    private function groupParentTrackedSequence(array $target): array
    {
        $groups = [];
        $currentGroup = [];
        $currentParent = null;
        $hasCurrentParent = false;

        foreach ($target as $item) {
            if (! $this->isTuple($item) || $this->tupleParent($item) === null) {
                if ($currentGroup !== []) {
                    $groups[] = $currentGroup;
                    $currentGroup = [];
                    $hasCurrentParent = false;
                    $currentParent = null;
                }

                $groups[] = [$item];

                continue;
            }

            $parent = $this->tupleParent($item);

            if (! $hasCurrentParent || $parent != $currentParent) {
                if ($currentGroup !== []) {
                    $groups[] = $currentGroup;
                }

                $currentGroup = [$item];
                $currentParent = $parent;
                $hasCurrentParent = true;

                continue;
            }

            $currentGroup[] = $item;
        }

        if ($currentGroup !== []) {
            $groups[] = $currentGroup;
        }

        return $groups;
    }

    /**
     * @param  array<int, mixed>  $indexes
     * @return array<int, mixed>
     */
    private function flattenSubscriptIndexes(array $indexes): array
    {
        $flattened = [];

        foreach ($indexes as $index) {
            if (is_array($index) && array_is_list($index)) {
                foreach ($this->flattenSubscriptIndexes($index) as $nested) {
                    $flattened[] = $nested;
                }

                continue;
            }

            $flattened[] = $index;
        }

        return $flattened;
    }

    private function evaluateWildcard(mixed $target): mixed
    {
        if ($this->isMissing($target) || $target === null) {
            return $this->missingValue;
        }

        if ($this->isTuple($target)) {
            return $this->wrapTupleResult(
                $this->evaluateWildcard($this->tupleValue($target)),
                $this->tupleBindings($target)
            );
        }

        if (is_array($target) && array_is_list($target)) {
            $values = [];

            foreach ($target as $item) {
                $value = $this->evaluateWildcard($item);
                if ($this->isMissing($value)) {
                    if (! is_array($item)) {
                        $values[] = $this->wrapTupleResult($item, []);
                    }

                    continue;
                }

                if (is_array($value) && array_is_list($value)) {
                    foreach ($value as $nested) {
                        $values[] = $this->wrapTupleResult($nested, []);
                    }
                } else {
                    $values[] = $this->wrapTupleResult($value, []);
                }
            }

            return $this->collapseSequence($values);
        }

        if (is_array($target)) {
            $values = [];

            foreach (array_values($target) as $value) {
                if (is_array($value) && array_is_list($value)) {
                    foreach ($value as $nestedValue) {
                        $values[] = $this->wrapTupleResult($nestedValue, []);
                    }

                    continue;
                }

                $values[] = $this->wrapTupleResult($value, []);
            }

            return $this->collapseSequence($values);
        }

        return $this->missingValue;
    }

    private function evaluateDescendant(mixed $target): mixed
    {
        if ($this->isMissing($target) || $target === null) {
            return $this->missingValue;
        }

        if ($this->isTuple($target)) {
            return $this->wrapTupleResult(
                $this->evaluateDescendant($this->tupleValue($target)),
                $this->tupleBindings($target)
            );
        }

        $results = [];

        if (is_array($target) && array_is_list($target)) {
            foreach ($target as $item) {
                $value = $this->isTuple($item) ? $this->tupleValue($item) : $item;
                $this->collectDescendants($value, $results, true, $value);
            }
        } else {
            $this->collectDescendants($target, $results, true, $target);
        }

        return $this->collapseSequence($results);
    }

    /**
     * @param  array<string, mixed>  $ast
     * @param  array<string, mixed>  $environment
     */
    private function evaluatePathStep(array $ast, mixed $context, array &$environment, mixed $rootContext): mixed
    {
        $target = $this->evaluateAst($ast['target'], $context, $environment, $rootContext);
        $results = [];

        foreach ($this->pathInputSequence($target) as $item) {
            $value = match ($ast['step']['type'] ?? null) {
                'call' => $this->evaluatePathStepCall($ast['step'], $item, $environment, $rootContext),
                'partial' => $this->evaluateChain($ast['step'], $item, $item, $environment, $rootContext),
                default => $this->evaluateAst($ast['step'], $item, $environment, $rootContext),
            };

            if ($this->isMissing($value)) {
                continue;
            }

            if ($this->isTuple($value)
                && is_array($this->tupleValue($value))
                && array_is_list($this->tupleValue($value))
                && ! $this->pathStepPreservesArray($ast['step'])) {
                foreach ($this->pathInputSequence($value) as $nestedValue) {
                    $results[] = $nestedValue;
                }

                continue;
            }

            if (is_array($value) && array_is_list($value) && ! $this->pathStepPreservesArray($ast['step'])) {
                foreach ($value as $nestedValue) {
                    $results[] = $nestedValue;
                }

                continue;
            }

            $results[] = $value;
        }

        if (($ast['step']['type'] ?? null) === 'group') {
            return $this->mergePathStepGroups($results);
        }

        return $this->hasArrayConstructorRoot($ast['target']) || ($ast['step']['type'] ?? null) === 'array_constructor'
            ? array_values($results)
            : $this->collapseSequence($results);
    }

    /**
     * @param  array<int, mixed>  $groups
     * @return array<string, mixed>
     */
    private function mergePathStepGroups(array $groups): array
    {
        $merged = [];

        foreach ($groups as $group) {
            $group = $this->tupleValue($group);

            if (! is_array($group) || array_is_list($group)) {
                continue;
            }

            foreach ($group as $key => $value) {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }

    /**
     * @return array<int, mixed>
     */
    private function pathInputSequence(mixed $target): array
    {
        if ($this->isTuple($target) && is_array($this->tupleValue($target)) && array_is_list($this->tupleValue($target))) {
            return $this->toSequence(
                $this->wrapTupleResult($this->tupleValue($target), $this->tupleBindings($target), $this->tupleParent($target))
            );
        }

        return $this->toSequence($target);
    }

    /**
     * @param  array<string, mixed>  $ast
     */
    private function pathStepPreservesArray(array $ast): bool
    {
        if ($this->astContainsType($ast, 'parent_context')) {
            return false;
        }

        if (in_array($ast['type'] ?? null, ['array', 'array_constructor'], true)) {
            return true;
        }

        return ($ast['type'] ?? null) === 'call'
            && ($ast['callee']['type'] ?? null) === 'variable'
            && ($ast['callee']['name'] ?? null) === '$zip';
    }

    /**
     * @param  array<string, mixed>  $ast
     */
    private function astContainsType(array $ast, string $type): bool
    {
        if (($ast['type'] ?? null) === $type) {
            return true;
        }

        foreach ($ast as $value) {
            if (is_array($value) && $this->astContainsType($value, $type)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $ast
     */
    private function hasArrayConstructorRoot(array $ast): bool
    {
        if (($ast['type'] ?? null) === 'array_constructor') {
            return true;
        }

        foreach (['target', 'callee'] as $key) {
            if (isset($ast[$key]) && is_array($ast[$key]) && $this->hasArrayConstructorRoot($ast[$key])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $ast
     * @param  array<string, mixed>  $environment
     */
    private function evaluatePathStepCall(array $ast, mixed $context, array &$environment, mixed $rootContext): mixed
    {
        $callee = $this->evaluateAst($ast['callee'], $context, $environment, $rootContext);

        if (! $callee instanceof Closure) {
            $this->throwNonFunctionCall($ast, $environment);
        }

        $arguments = [];
        foreach ($ast['arguments'] as $argument) {
            $arguments[] = $this->normalizePreservingMissingPublic(
                $this->evaluateAst($argument, $context, $environment, $rootContext)
            );
        }

        if (count($arguments) < $this->functions->functionArity($callee)) {
            array_unshift($arguments, $this->normalizePreservingMissingPublic($this->tupleValue($context)));
        }

        return $callee($arguments, $context);
    }

    /**
     * @param  array<string, mixed>  $ast
     * @param  array<string, mixed>  $environment
     */
    private function evaluateParent(array $ast, mixed $context, array &$environment, mixed $rootContext): mixed
    {
        $target = $this->evaluateAst($ast['target'], $context, $environment, $rootContext);
        $items = $this->toSequence($target);
        $parents = [];

        foreach ($items as $item) {
            if (! $this->isTuple($item) || $this->tupleParent($item) === null) {
                continue;
            }

            $parents[] = $this->tupleParent($item);
        }

        if ($parents === [] && $items !== []) {
            throw new EvaluationException(
                "Error S0217: The object representing the 'parent' cannot be derived from this expression",
                'S0217'
            );
        }

        return $this->collapseSequence($parents);
    }

    /**
     * @param  array<string, mixed>  $ast
     */
    private function evaluateParentContext(array $ast, mixed $context): mixed
    {
        if (! $this->isTuple($context) || $this->tupleParent($context) === null) {
            throw new EvaluationException(
                "Error S0217: The object representing the 'parent' cannot be derived from this expression",
                'S0217',
                (int) ($ast['position'] ?? 0)
            );
        }

        $parent = $this->tupleParent($context);

        return $this->wrapTupleResult(
            $this->tupleValue($parent),
            $this->tupleBindings($parent),
            $this->tupleParentContextParent($context) ?? $this->tupleParent($parent)
        );
    }

    /**
     * @param  array<string, mixed>  $ast
     * @param  array<string, mixed>  $environment
     * @return array<int, mixed>
     */
    private function collectParentValues(array $ast, mixed $context, array &$environment, mixed $rootContext): array
    {
        return match ($ast['type']) {
            'identifier' => $this->expandParentMatches($context, $this->resolveIdentifier((string) $ast['name'], $context)),
            'parent_context' => ($this->isTuple($context) && $this->tupleParent($context) !== null)
                ? [$this->tupleParent($context)]
                : [],
            'variable' => ($ast['name'] ?? null) === '$'
                ? $this->expandParentMatches($context, $this->tupleValue($context))
                : [],
            'parent' => $this->collectNestedParentValues($ast, $context, $environment, $rootContext),
            'property' => $this->collectPropertyParentValues($ast, $context, $environment, $rootContext),
            'subscript' => $this->collectSubscriptParentValues($ast, $context, $environment, $rootContext),
            'wildcard' => $this->collectWildcardParentValues($ast, $context, $environment, $rootContext),
            'filter' => $this->collectFilterParentValues($ast, $context, $environment, $rootContext),
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $ast
     * @param  array<string, mixed>  $environment
     * @return array<int, mixed>
     */
    private function collectPropertyParentValues(array $ast, mixed $context, array &$environment, mixed $rootContext): array
    {
        $baseTarget = $this->evaluateAst($ast['target'], $context, $environment, $rootContext);
        $parents = [];

        foreach ($this->toSequence($baseTarget) as $item) {
            $parents = [
                ...$parents,
                ...$this->expandParentMatches($item, $this->accessProperty($item, (string) $ast['name'])),
            ];
        }

        return $parents;
    }

    /**
     * @param  array<string, mixed>  $ast
     * @param  array<string, mixed>  $environment
     * @return array<int, mixed>
     */
    private function collectSubscriptParentValues(array $ast, mixed $context, array &$environment, mixed $rootContext): array
    {
        $baseTarget = $this->evaluateAst($ast['target'], $context, $environment, $rootContext);
        $index = $this->evaluateAst($ast['index'], $context, $environment, $rootContext);
        $parents = [];

        foreach ($this->toSequence($baseTarget) as $item) {
            $parents = [
                ...$parents,
                ...$this->expandParentMatches($item, $this->accessSubscript($item, $index)),
            ];
        }

        return $parents;
    }

    /**
     * @param  array<string, mixed>  $ast
     * @param  array<string, mixed>  $environment
     * @return array<int, mixed>
     */
    private function collectWildcardParentValues(array $ast, mixed $context, array &$environment, mixed $rootContext): array
    {
        $baseTarget = $this->evaluateAst($ast['target'], $context, $environment, $rootContext);
        $parents = [];

        foreach ($this->toSequence($baseTarget) as $item) {
            $parents = [
                ...$parents,
                ...$this->expandParentMatches($item, $this->evaluateWildcard($item)),
            ];
        }

        return $parents;
    }

    /**
     * @param  array<string, mixed>  $ast
     * @param  array<string, mixed>  $environment
     * @return array<int, mixed>
     */
    private function collectFilterParentValues(array $ast, mixed $context, array &$environment, mixed $rootContext): array
    {
        $baseTarget = $this->evaluateAst($ast['target'], $context, $environment, $rootContext);
        $parents = [];

        foreach ($this->toSequence($baseTarget) as $item) {
            if ($this->isTruthy($this->evaluateAst($ast['predicate'], $item, $environment, $rootContext))) {
                $parents[] = $item;
            }
        }

        return $parents;
    }

    /**
     * @param  array<string, mixed>  $ast
     * @param  array<string, mixed>  $environment
     * @return array<int, mixed>
     */
    private function collectNestedParentValues(array $ast, mixed $context, array &$environment, mixed $rootContext): array
    {
        $baseTarget = $this->evaluateAst($ast['target'], $context, $environment, $rootContext);
        $parents = [];

        foreach ($this->toSequence($baseTarget) as $item) {
            if (! $this->isTuple($item) || $this->tupleParent($item) === null) {
                continue;
            }

            $parents[] = $this->tupleParent($item);
        }

        return $parents;
    }

    /**
     * @return array<int, mixed>
     */
    private function expandParentMatches(mixed $parent, mixed $result): array
    {
        if ($this->isMissing($result) || $result === null) {
            return [];
        }

        if (is_array($result) && array_is_list($result)) {
            return array_fill(0, count($result), $parent);
        }

        return [$parent];
    }

    /**
     * @param  array<int, mixed>  $results
     */
    private function collectDescendants(mixed $value, array &$results, bool $includeSelf, mixed $parent = null): void
    {
        if ($this->isMissing($value) || $value === null) {
            return;
        }

        if ($includeSelf && (! is_array($value) || ! array_is_list($value))) {
            $results[] = $this->wrapTupleResult($value, []);
        }

        if (! is_array($value)) {
            return;
        }

        foreach ($value as $child) {
            $this->collectDescendants($child, $results, true, $value);
        }
    }

    /**
     * @param  array<string, mixed>  $ast
     * @param  array<string, mixed>  $environment
     */
    private function filterSequence(array $ast, mixed $context, array &$environment, mixed $rootContext): mixed
    {
        $sequence = ($ast['target']['type'] ?? null) === 'variable' && ($ast['target']['name'] ?? null) === '$' && $this->isTuple($context)
            ? $context
            : $this->evaluateAst($ast['target'], $context, $environment, $rootContext);
        $items = $this->pathInputSequence($sequence);

        if ($items === []) {
            return $this->missingValue;
        }

        $matches = [];

        foreach ($items as $index => $item) {
            $predicate = $this->evaluateAst($ast['predicate'], $item, $environment, $rootContext);

            if ($this->isNumericSelector($predicate)) {
                $resolvedIndex = $this->normalizeNumericSelector($predicate);
                $resolvedIndex = $resolvedIndex < 0 ? count($items) + $resolvedIndex : $resolvedIndex;

                if ($index === $resolvedIndex) {
                    $matches[] = $item;
                }

                continue;
            }

            if ($this->isTruthy($predicate)) {
                $matches[] = $item;
            }
        }

        return $this->hasArrayConstructorRoot($ast['target'])
            ? array_values($matches)
            : $this->collapseSequence($matches);
    }

    /**
     * @param  array<string, mixed>  $ast
     * @param  array<string, mixed>  $environment
     */
    private function evaluateObjectLiteral(array $ast, mixed $context, array &$environment, mixed $rootContext): mixed
    {
        if ($ast['pairs'] === []) {
            return new stdClass;
        }

        $object = [];

        foreach ($ast['pairs'] as $pair) {
            $keyResult = $this->evaluateAst($pair['key'], $context, $environment, $rootContext);
            $this->assertObjectKeyResult($keyResult, $pair, $ast);

            $keys = $this->toSequence($keyResult);
            $value = $this->evaluateAst($pair['value'], $context, $environment, $rootContext);

            if ($this->isMissing($value)) {
                continue;
            }

            $value = $this->normalizeValuePublic($value);

            foreach ($keys as $key) {
                if ($this->isMissing($key) || $key === null) {
                    continue;
                }

                $key = $this->normalizePreservingMissingPublic($key);

                if (! is_string($key)) {
                    throw new EvaluationException(
                        sprintf('Error T1003: Key in object structure must evaluate to a string; got: %s', $this->stringify($key)),
                        'T1003',
                        (int) ($pair['key']['position'] ?? $ast['position'] ?? 0)
                    );
                }

                if (array_key_exists($key, $object)) {
                    throw new EvaluationException(
                        sprintf('Error D1009: Multiple key definitions evaluate to same key: "%s"', $key),
                        'D1009',
                        (int) ($pair['key']['position'] ?? $ast['position'] ?? 0)
                    );
                }

                $object[$key] = $this->isMissing($value) ? null : $value;
            }
        }

        return $object;
    }

    /**
     * @param  array<string, mixed>  $ast
     * @param  array<string, mixed>  $environment
     * @return array<string, mixed>
     */
    private function evaluateGroup(array $ast, mixed $context, array &$environment, mixed $rootContext): array
    {
        $sequence = $this->pathInputSequence(
            $this->evaluateAst($ast['target'], $context, $environment, $rootContext)
        );
        $grouped = [];
        $deferredGroups = [];

        foreach ($sequence as $item) {
            $itemKeys = [];

            foreach ($ast['pairs'] as $pair) {
                $keyResult = $this->evaluateAst($pair['key'], $item, $environment, $rootContext);
                $this->assertObjectKeyResult($keyResult, $pair, $ast);

                $keys = $this->toSequence($keyResult);
                $deferValue = $this->isDeferredGroupValue($pair['value']);
                $value = $this->missingValue;

                if (! $deferValue) {
                    $value = $this->evaluateAst($pair['value'], $item, $environment, $rootContext);

                    if ($this->isMissing($value)) {
                        continue;
                    }
                }

                foreach ($keys as $key) {
                    if ($this->isMissing($key) || $key === null) {
                        continue;
                    }

                    $key = $this->normalizePreservingMissingPublic($key);

                    if (! is_string($key)) {
                        throw new EvaluationException(
                            sprintf('Error T1003: Key in object structure must evaluate to a string; got: %s', $this->stringify($key)),
                            'T1003',
                            (int) ($pair['key']['position'] ?? $ast['position'] ?? 0)
                        );
                    }

                    if (array_key_exists($key, $itemKeys)) {
                        throw new EvaluationException(
                            sprintf('Error D1009: Multiple key definitions evaluate to same key: "%s"', $key),
                            'D1009',
                            (int) ($pair['key']['position'] ?? $ast['position'] ?? 0)
                        );
                    }

                    $itemKeys[$key] = true;

                    if ($deferValue) {
                        $deferredGroups[$key] ??= [
                            'ast' => $pair['value'],
                            'items' => [],
                        ];
                        $deferredGroups[$key]['items'][] = $item;

                        continue;
                    }

                    if (! array_key_exists($key, $grouped)) {
                        $grouped[$key] = $value;

                        continue;
                    }

                    if (in_array($pair['value']['type'] ?? null, ['call', 'subscript'], true)
                        && $this->groupedValueContains($grouped[$key], $value)) {
                        continue;
                    }

                    if (($pair['value']['type'] ?? null) === 'array_constructor' && is_array($value) && array_is_list($value)) {
                        if (! is_array($grouped[$key]) || ! array_is_list($grouped[$key])) {
                            $grouped[$key] = [$grouped[$key]];
                        }

                        foreach ($value as $nestedValue) {
                            $grouped[$key][] = $nestedValue;
                        }

                        continue;
                    }

                    if (! is_array($grouped[$key]) || ! array_is_list($grouped[$key])) {
                        $grouped[$key] = [$grouped[$key]];
                    }

                    $grouped[$key][] = $value;
                }
            }
        }

        foreach ($deferredGroups as $key => $deferredGroup) {
            $value = $this->evaluateDeferredGroupValue(
                $deferredGroup['ast'],
                $deferredGroup['items'],
                $environment,
                $rootContext
            );

            if (! $this->isMissing($value)) {
                $grouped[$key] = $value;
            }
        }

        return $grouped;
    }

    /**
     * @param  array<string, mixed>  $ast
     */
    private function isDeferredGroupValue(array $ast): bool
    {
        return ($ast['type'] ?? null) === 'call'
            || ($ast['type'] ?? null) === 'sort'
            || (($ast['type'] ?? null) === 'binary' && ($ast['operator'] ?? null) === '~>');
    }

    /**
     * @param  array<string, mixed>  $ast
     * @param  array<int, mixed>  $items
     * @param  array<string, mixed>  $environment
     */
    private function evaluateDeferredGroupValue(array $ast, array $items, array &$environment, mixed $rootContext): mixed
    {
        $context = $this->collapseSequence($items);

        if (($ast['type'] ?? null) === 'call') {
            $callee = $this->evaluateAst($ast['callee'], $items[0] ?? $context, $environment, $rootContext);

            if (! $callee instanceof Closure) {
                throw new EvaluationException(
                    'Error T1006: Attempted to call a non-function value.',
                    'T1006'
                );
            }

            $arguments = [];
            foreach ($ast['arguments'] as $argument) {
                $arguments[] = $this->normalizePreservingMissingPublic(
                    $this->evaluateDeferredGroupArgument($argument, $items, $environment, $rootContext)
                );
            }

            return $callee($arguments, $context);
        }

        if (($ast['type'] ?? null) === 'sort') {
            return $this->evaluateDeferredGroupSort($ast, $items, $environment, $rootContext);
        }

        if (($ast['type'] ?? null) === 'binary' && ($ast['operator'] ?? null) === '~>') {
            $input = $this->evaluateDeferredGroupArgument($ast['left'], $items, $environment, $rootContext);

            return $this->evaluateChain($ast['right'], $input, $context, $environment, $rootContext);
        }

        return $this->evaluateAst($ast, $context, $environment, $rootContext);
    }

    /**
     * @param  array<string, mixed>  $ast
     * @param  array<int, mixed>  $items
     * @param  array<string, mixed>  $environment
     */
    private function evaluateDeferredGroupArgument(array $ast, array $items, array &$environment, mixed $rootContext): mixed
    {
        if (($ast['type'] ?? null) === 'literal') {
            return $this->evaluateAst($ast, $items[0] ?? $this->missingValue, $environment, $rootContext);
        }

        $values = [];

        foreach ($items as $item) {
            $value = $this->evaluateAst($ast, $item, $environment, $rootContext);

            if ($this->isMissing($value)) {
                continue;
            }

            foreach ($this->toSequence($value) as $nestedValue) {
                $values[] = $nestedValue;
            }
        }

        return $this->collapseSequence($values);
    }

    /**
     * @param  array<string, mixed>  $ast
     * @param  array<int, mixed>  $items
     * @param  array<string, mixed>  $environment
     */
    private function evaluateDeferredGroupSort(array $ast, array $items, array &$environment, mixed $rootContext): mixed
    {
        $values = $this->pathInputSequence(
            $this->evaluateDeferredGroupArgument($ast['target'], $items, $environment, $rootContext)
        );
        $sorted = $values;

        usort($sorted, function (mixed $left, mixed $right) use ($ast, &$environment, $rootContext): int {
            foreach ($ast['terms'] as $term) {
                $leftValue = $this->evaluateAst($term['expression'], $left, $environment, $rootContext);
                $rightValue = $this->evaluateAst($term['expression'], $right, $environment, $rootContext);
                $comparison = $this->compareSortValues($leftValue, $rightValue);

                if ($comparison !== 0) {
                    return $term['descending'] ? -$comparison : $comparison;
                }
            }

            return 0;
        });

        return $this->collapseSequence($sorted);
    }

    private function groupedValueContains(mixed $existing, mixed $value): bool
    {
        if (is_array($existing) && array_is_list($existing)) {
            foreach ($existing as $item) {
                if ($this->compareValues($item, $value)) {
                    return true;
                }
            }

            return false;
        }

        return $this->compareValues($existing, $value);
    }

    /**
     * @param  array<string, mixed>  $pair
     * @param  array<string, mixed>  $ast
     */
    private function assertObjectKeyResult(mixed $key, array $pair, array $ast): void
    {
        $key = $this->normalizePreservingMissingPublic($key);

        if (is_array($key) && array_is_list($key)) {
            throw new EvaluationException(
                sprintf('Error T1003: Key in object structure must evaluate to a string; got: %s', $this->stringify($key)),
                'T1003',
                (int) ($pair['key']['position'] ?? $ast['position'] ?? 0)
            );
        }
    }

    /**
     * @param  array<string, mixed>  $ast
     * @param  array<string, mixed>  $environment
     */
    private function createClosure(array $ast, mixed $definitionContext, array &$environment, mixed $rootContext): Closure
    {
        $closure = function (array $arguments, mixed $callContext = null) use ($ast, $definitionContext, &$environment, $rootContext): mixed {
            $localEnvironment = $environment;
            $effectiveContext = $ast['parameters'] === []
                ? $definitionContext
                : ($callContext ?? $definitionContext);

            if (($ast['signature'] ?? null) !== null) {
                $arguments = Signature::parse($ast['signature'])->validate($arguments, $effectiveContext, $this);
            }

            foreach ($ast['parameters'] as $index => $parameter) {
                if (array_key_exists($index, $arguments)) {
                    $localEnvironment[$parameter] = $arguments[$index];
                }
            }

            return $this->evaluateAst($ast['body'], $effectiveContext, $localEnvironment, $rootContext);
        };

        return $this->functions->registerFunctionArity($closure, count($ast['parameters']));
    }

    /**
     * @param  array<string, mixed>  $ast
     * @param  array<string, mixed>  $environment
     */
    private function createTransformClosure(array $ast, array $environment, mixed $rootContext): Closure
    {
        $closure = function (array $arguments, mixed $callContext = null) use ($ast, $environment): mixed {
            $input = $arguments[0] ?? $this->missingValue;

            if ($this->isMissing($input)) {
                return $this->missingValue;
            }

            $clone = $environment['$clone'] ?? null;
            if (! $clone instanceof Closure) {
                throw new EvaluationException(
                    'Error T2013: The transform expression clones the input object using the $clone() function.  This has been overridden in the current scope by a non-function.',
                    'T2013',
                    (int) ($ast['position'] ?? 0)
                );
            }

            $result = $clone([$input], $callContext ?? $input);
            if ($this->isMissing($result) || $result === null) {
                return $result;
            }

            $transformRootContext = is_array($result) ? $result : ['value' => $result];
            $localEnvironment = $environment;
            $paths = $this->normalizeTransformMatchPaths(
                $this->resolveTransformPaths($ast['pattern'], $result, [], $localEnvironment, $transformRootContext),
                $result
            );

            foreach ($paths as $path) {
                $match = &$this->referenceAtPath($result, $path);
                $update = $this->evaluateAst($ast['update'], $match, $localEnvironment, $transformRootContext);

                if (! $this->isMissing($update) && $update !== null) {
                    $updateIsEmptyObjectLiteral = ($ast['update']['type'] ?? null) === 'object'
                        && ($ast['update']['pairs'] ?? []) === []
                        && $update instanceof stdClass
                        && get_object_vars($update) === [];
                    $updateProperties = $updateIsEmptyObjectLiteral ? [] : $update;

                    if (! $updateIsEmptyObjectLiteral && (! is_array($updateProperties) || array_is_list($updateProperties))) {
                        throw new EvaluationException(
                            sprintf(
                                'Error T2011: The insert/update clause of the transform expression must evaluate to an object: %s',
                                $this->stringify($update)
                            ),
                            'T2011',
                            (int) ($ast['position'] ?? 0)
                        );
                    }

                    if (! is_array($match)) {
                        throw new EvaluationException(
                            'Error T2011: The insert/update clause of the transform expression must target an object value.',
                            'T2011',
                            (int) ($ast['position'] ?? 0)
                        );
                    }

                    foreach ($updateProperties as $property => $value) {
                        $match[$property] = $value;
                    }
                }

                if (! array_key_exists('delete', $ast) || $ast['delete'] === null) {
                    continue;
                }

                $deletions = $this->evaluateAst($ast['delete'], $match, $localEnvironment, $transformRootContext);
                if ($this->isMissing($deletions) || $deletions === null) {
                    continue;
                }

                foreach ($this->normalizeTransformDeleteKeys($deletions, $ast) as $property) {
                    if (is_array($match)) {
                        unset($match[$property]);
                    }
                }
            }

            return $result;
        };

        return $this->functions->registerFunctionArity($closure, 1);
    }

    /**
     * @param  array<string, mixed>  $ast
     * @param  array<string, mixed>  $environment
     */
    private function evaluateCall(array $ast, mixed $context, array &$environment, mixed $rootContext): mixed
    {
        try {
            $callee = $this->evaluateAst($ast['callee'], $context, $environment, $rootContext);
        } catch (EvaluationException $exception) {
            if (($ast['callee']['type'] ?? null) !== 'parent_context' || $exception->jsonataCode !== 'S0217') {
                throw $exception;
            }

            $this->throwNonFunctionCall($ast, $environment);
        }

        if (! $callee instanceof Closure) {
            $this->throwNonFunctionCall($ast, $environment);
        }

        $arguments = [];

        foreach ($ast['arguments'] as $argument) {
            $arguments[] = $this->normalizePreservingMissingPublic(
                $this->evaluateAst($argument, $context, $environment, $rootContext)
            );
        }

        return $callee($arguments, $context);
    }

    /**
     * @param  array<string, mixed>  $ast
     * @param  array<string, mixed>  $environment
     */
    private function throwNonFunctionCall(array $ast, array $environment): never
    {
        $bareCallee = ($ast['callee']['type'] ?? null) === 'identifier'
            ? (string) ($ast['callee']['name'] ?? '')
            : null;

        if ($bareCallee !== null && $bareCallee !== '' && array_key_exists('$'.$bareCallee, $environment)) {
            throw new EvaluationException(
                sprintf('Error T1005: Attempted to invoke a non-function. Did you mean $%s?', $bareCallee),
                'T1005'
            );
        }

        throw new EvaluationException(
            'Error T1006: Attempted to call a non-function value.',
            'T1006'
        );
    }

    /**
     * @param  array<string, mixed>  $ast
     * @param  array<string, mixed>  $environment
     */
    private function evaluatePartial(array $ast, mixed $context, array &$environment, mixed $rootContext): Closure
    {
        $callee = $this->evaluateAst($ast['callee'], $context, $environment, $rootContext);

        if (! $callee instanceof Closure) {
            $bareCallee = ($ast['callee']['type'] ?? null) === 'identifier'
                ? (string) ($ast['callee']['name'] ?? '')
                : null;

            if ($bareCallee !== null && array_key_exists('$'.$bareCallee, $environment)) {
                throw new EvaluationException(
                    sprintf(
                        'Error T1007: Attempted to partially apply a non-function. Did you mean $%s?',
                        $bareCallee
                    ),
                    'T1007'
                );
            }

            throw new EvaluationException(
                'Error T1008: Attempted to partially apply a non-function value.',
                'T1008'
            );
        }

        return $this->createPartialApplication($callee, $ast['arguments'], $context, $environment, $rootContext);
    }

    /**
     * @param  array<string, mixed>  $ast
     * @param  array<string, mixed>  $environment
     */
    private function evaluateChain(array $ast, mixed $input, mixed $context, array &$environment, mixed $rootContext): mixed
    {
        if ($input instanceof Closure) {
            $next = $this->evaluateAst($ast, $context, $environment, $rootContext);

            if (! $next instanceof Closure) {
                throw new EvaluationException(
                    'Error T2006: The right side of the function application operator ~> must be a function.',
                    'T2006'
                );
            }

            return $this->composeClosures($input, $next);
        }

        if (($ast['type'] ?? null) === 'array_constructor') {
            return $this->arrayConstructorFromValue(
                $this->evaluateChain($ast['target'], $input, $context, $environment, $rootContext)
            );
        }

        if (($ast['type'] ?? null) === 'call') {
            $callee = $this->evaluateAst($ast['callee'], $context, $environment, $rootContext);

            if (! $callee instanceof Closure) {
                if ($callee instanceof RegexPattern) {
                    return preg_match($callee->toPcre(), $this->stringify($input)) === 1;
                }

                throw new EvaluationException(
                    'Error T2006: The right side of the function application operator ~> must be a function.',
                    'T2006'
                );
            }

            $arguments = [$this->normalizePreservingMissingPublic($input)];

            foreach ($ast['arguments'] as $argument) {
                $arguments[] = $this->normalizePreservingMissingPublic(
                    $this->evaluateAst($argument, $context, $environment, $rootContext)
                );
            }

            return $callee($arguments, $context);
        }

        if (($ast['type'] ?? null) === 'partial') {
            $callee = $this->evaluateAst($ast, $context, $environment, $rootContext);

            if (! $callee instanceof Closure) {
                throw new EvaluationException(
                    'Error T2006: The right side of the function application operator ~> must be a function.',
                    'T2006'
                );
            }

            return $callee([$this->normalizePreservingMissingPublic($input)], $context);
        }

        $callee = $this->evaluateAst($ast, $context, $environment, $rootContext);

        if (! $callee instanceof Closure) {
            if ($callee instanceof RegexPattern) {
                return preg_match($callee->toPcre(), $this->stringify($input)) === 1;
            }

            throw new EvaluationException(
                'Error T2006: The right side of the function application operator ~> must be a function.',
                'T2006'
            );
        }

        return $callee([$this->normalizePreservingMissingPublic($input)], $context);
    }

    private function composeClosures(Closure $left, Closure $right): Closure
    {
        $arity = $this->functions->functionArity($left);

        $closure = function (array $providedArguments, mixed $callContext = null) use ($left, $right): mixed {
            $intermediate = $left($providedArguments, $callContext);

            return $right([$intermediate], $callContext);
        };

        return $this->functions->registerFunctionArity($closure, $arity);
    }

    /**
     * @param  array<int, array<string, mixed>>  $arguments
     */
    private function containsPlaceholder(array $arguments): bool
    {
        foreach ($arguments as $argument) {
            if (($argument['type'] ?? null) === 'placeholder') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, array<string, mixed>>  $argumentAsts
     * @param  array<string, mixed>  $environment
     */
    private function createPartialApplication(
        Closure $callee,
        array $argumentAsts,
        mixed $context,
        array $environment,
        mixed $rootContext,
        array $boundArguments = [],
    ): Closure {
        $closure = function (array $providedArguments, mixed $callContext = null) use (
            $callee,
            $argumentAsts,
            $context,
            $environment,
            $rootContext,
            $boundArguments
        ): mixed {
            $effectiveContext = $callContext ?? $context;
            [$resolvedArguments, $remainingPlaceholders] = $this->resolveCallArguments(
                $argumentAsts,
                [...$boundArguments, ...$providedArguments],
                $effectiveContext,
                $environment,
                $rootContext
            );

            if ($remainingPlaceholders > 0) {
                return $this->createPartialApplication(
                    $callee,
                    $argumentAsts,
                    $effectiveContext,
                    $environment,
                    $rootContext,
                    [...$boundArguments, ...$providedArguments]
                );
            }

            return $callee($resolvedArguments, $effectiveContext);
        };

        return $this->functions->registerFunctionArity(
            $closure,
            $this->remainingPlaceholderCount($argumentAsts, count($boundArguments))
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $argumentAsts
     * @param  array<int, mixed>  $providedArguments
     * @param  array<string, mixed>  $environment
     * @return array{0: array<int, mixed>, 1: int}
     */
    private function resolveCallArguments(
        array $argumentAsts,
        array $providedArguments,
        mixed $context,
        array $environment,
        mixed $rootContext,
    ): array {
        $resolvedArguments = [];
        $providedIndex = 0;
        $remainingPlaceholders = 0;

        foreach ($argumentAsts as $argumentAst) {
            if (($argumentAst['type'] ?? null) === 'placeholder') {
                if (array_key_exists($providedIndex, $providedArguments)) {
                    $resolvedArguments[] = $providedArguments[$providedIndex];
                    $providedIndex++;
                } else {
                    $remainingPlaceholders++;
                }

                continue;
            }

            $resolvedArguments[] = $this->normalizePreservingMissingPublic(
                $this->evaluateAst($argumentAst, $context, $environment, $rootContext)
            );
        }

        while (array_key_exists($providedIndex, $providedArguments)) {
            $resolvedArguments[] = $providedArguments[$providedIndex];
            $providedIndex++;
        }

        return [$resolvedArguments, $remainingPlaceholders];
    }

    /**
     * @param  array<int, array<string, mixed>>  $argumentAsts
     */
    private function remainingPlaceholderCount(array $argumentAsts, int $boundArgumentCount): int
    {
        $remaining = 0;
        $consumed = 0;

        foreach ($argumentAsts as $argumentAst) {
            if (($argumentAst['type'] ?? null) !== 'placeholder') {
                continue;
            }

            if ($consumed < $boundArgumentCount) {
                $consumed++;

                continue;
            }

            $remaining++;
        }

        return $remaining;
    }

    /**
     * @param  array<string, mixed>  $ast
     * @param  array<string, mixed>  $environment
     */
    private function evaluateSort(array $ast, mixed $context, array &$environment, mixed $rootContext): mixed
    {
        $items = $this->pathInputSequence($this->evaluateAst($ast['target'], $context, $environment, $rootContext));
        $sorted = $items;

        usort($sorted, function (mixed $left, mixed $right) use ($ast, &$environment, $rootContext): int {
            foreach ($ast['terms'] as $term) {
                $leftValue = $this->evaluateAst($term['expression'], $left, $environment, $rootContext);
                $rightValue = $this->evaluateAst($term['expression'], $right, $environment, $rootContext);
                $comparison = $this->compareSortValues($leftValue, $rightValue);

                if ($comparison !== 0) {
                    return $term['descending'] ? -$comparison : $comparison;
                }
            }

            return 0;
        });

        return $this->collapseSequence($sorted);
    }

    /**
     * @param  array<string, mixed>  $ast
     * @param  array<string, mixed>  $environment
     */
    private function evaluateObjectMap(array $ast, mixed $context, array &$environment, mixed $rootContext): mixed
    {
        $items = $this->pathInputSequence($this->evaluateAst($ast['target'], $context, $environment, $rootContext));
        $results = [];

        foreach ($items as $item) {
            $results[] = $this->evaluateObjectLiteral($ast['object'], $item, $environment, $rootContext);
        }

        return $this->hasArrayConstructorRoot($ast['target'])
            ? array_values($results)
            : $this->collapseSequence($results);
    }

    /**
     * @param  array<string, mixed>  $ast
     * @param  array<int, int|string>  $contextPath
     * @param  array<string, mixed>  $environment
     * @return array<int, array<int, int|string>>
     */
    private function resolveTransformPaths(array $ast, mixed &$root, array $contextPath, array &$environment, mixed $rootContext): array
    {
        return match ($ast['type']) {
            'identifier' => $this->resolveTransformIdentifierPaths((string) $ast['name'], $root, $contextPath),
            'grouping' => $this->normalizeTransformMatchPaths(
                $this->resolveTransformPaths($ast['expression'], $root, $contextPath, $environment, $rootContext),
                $root
            ),
            'property' => $this->resolveTransformPropertyPaths($ast, $root, $contextPath, $environment, $rootContext),
            'wildcard' => $this->resolveTransformWildcardPaths($ast, $root, $contextPath, $environment, $rootContext),
            'descendant' => $this->resolveTransformDescendantPaths($ast, $root, $contextPath, $environment, $rootContext),
            'descendant_context' => $this->descendantPaths($this->valueAtPath($root, $contextPath), $contextPath),
            'subscript' => $this->resolveTransformSubscriptPaths($ast, $root, $contextPath, $environment, $rootContext),
            'filter' => $this->resolveTransformFilterPaths($ast, $root, $contextPath, $environment, $rootContext),
            'sequence' => $this->resolveTransformSequencePaths($ast, $root, $contextPath, $environment, $rootContext),
            'assignment' => $this->resolveTransformAssignmentPaths($ast, $root, $contextPath, $environment, $rootContext),
            'call' => $this->resolveTransformCallPaths($ast, $root, $contextPath, $environment, $rootContext),
            'variable' => $this->resolveTransformVariablePaths((string) $ast['name'], $contextPath, $environment),
            default => [],
        };
    }

    /**
     * @param  array<int, int|string>  $contextPath
     * @return array<int, array<int, int|string>>
     */
    private function resolveTransformIdentifierPaths(string $name, mixed &$root, array $contextPath): array
    {
        $contextValue = $this->valueAtPath($root, $contextPath);

        if (is_array($contextValue) && array_is_list($contextValue)) {
            $paths = [];

            foreach ($contextValue as $index => $item) {
                if (is_array($item) && array_key_exists($name, $item)) {
                    $paths[] = [...$contextPath, $index, $name];
                }
            }

            return $paths;
        }

        if (! is_array($contextValue) || ! array_key_exists($name, $contextValue)) {
            return [];
        }

        return [[...$contextPath, $name]];
    }

    /**
     * @param  array<string, mixed>  $ast
     * @param  array<string, mixed>  $environment
     * @return array<int, array<int, int|string>>
     */
    private function resolveTransformPropertyPaths(array $ast, mixed &$root, array $contextPath, array &$environment, mixed $rootContext): array
    {
        $basePaths = $this->resolveTransformPaths($ast['target'], $root, $contextPath, $environment, $rootContext);
        $paths = [];

        foreach ($basePaths as $basePath) {
            $baseValue = $this->valueAtPath($root, $basePath);

            if (is_array($baseValue) && array_is_list($baseValue)) {
                foreach ($baseValue as $index => $item) {
                    if (is_array($item) && array_key_exists($ast['name'], $item)) {
                        $paths[] = [...$basePath, $index, $ast['name']];
                    }
                }

                continue;
            }

            if (is_array($baseValue) && array_key_exists($ast['name'], $baseValue)) {
                $paths[] = [...$basePath, $ast['name']];
            }
        }

        return $paths;
    }

    /**
     * @param  array<string, mixed>  $ast
     * @param  array<string, mixed>  $environment
     * @return array<int, array<int, int|string>>
     */
    private function resolveTransformWildcardPaths(array $ast, mixed &$root, array $contextPath, array &$environment, mixed $rootContext): array
    {
        $basePaths = $this->resolveTransformPaths($ast['target'], $root, $contextPath, $environment, $rootContext);
        $paths = [];

        foreach ($basePaths as $basePath) {
            foreach ($this->wildcardChildPaths($this->valueAtPath($root, $basePath), $basePath) as $path) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /**
     * @param  array<string, mixed>  $ast
     * @param  array<string, mixed>  $environment
     * @return array<int, array<int, int|string>>
     */
    private function resolveTransformDescendantPaths(array $ast, mixed &$root, array $contextPath, array &$environment, mixed $rootContext): array
    {
        $basePaths = $this->resolveTransformPaths($ast['target'], $root, $contextPath, $environment, $rootContext);
        $paths = [];

        foreach ($basePaths as $basePath) {
            foreach ($this->descendantPaths($this->valueAtPath($root, $basePath), $basePath) as $path) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /**
     * @param  array<string, mixed>  $ast
     * @param  array<string, mixed>  $environment
     * @return array<int, array<int, int|string>>
     */
    private function resolveTransformSubscriptPaths(array $ast, mixed &$root, array $contextPath, array &$environment, mixed $rootContext): array
    {
        $basePaths = $this->resolveTransformPaths($ast['target'], $root, $contextPath, $environment, $rootContext);
        $indexValue = $this->evaluateAst($ast['index'], $root, $environment, $rootContext);
        $paths = [];

        if (! is_int($indexValue)) {
            return [];
        }

        if (($ast['target']['type'] ?? null) === 'grouping') {
            $resolvedIndex = $indexValue < 0 ? count($basePaths) + $indexValue : $indexValue;

            return array_key_exists($resolvedIndex, $basePaths)
                ? [$basePaths[$resolvedIndex]]
                : [];
        }

        foreach ($basePaths as $basePath) {
            $baseValue = $this->valueAtPath($root, $basePath);
            if (is_array($baseValue) && array_is_list($baseValue) && array_key_exists($indexValue, $baseValue)) {
                $paths[] = [...$basePath, $indexValue];
            }
        }

        return $paths;
    }

    /**
     * @param  array<string, mixed>  $ast
     * @param  array<string, mixed>  $environment
     * @return array<int, array<int, int|string>>
     */
    private function resolveTransformFilterPaths(array $ast, mixed &$root, array $contextPath, array &$environment, mixed $rootContext): array
    {
        $basePaths = $this->resolveTransformPaths($ast['target'], $root, $contextPath, $environment, $rootContext);
        $paths = [];

        foreach ($basePaths as $basePath) {
            $baseValue = $this->valueAtPath($root, $basePath);

            if (is_array($baseValue) && array_is_list($baseValue)) {
                foreach ($baseValue as $index => $item) {
                    if ($this->isTruthy($this->evaluateAst($ast['predicate'], $item, $environment, $rootContext))) {
                        $paths[] = [...$basePath, $index];
                    }
                }

                continue;
            }

            $predicate = $this->evaluateAst($ast['predicate'], $baseValue, $environment, $rootContext);

            if (is_string($predicate)) {
                if (is_array($baseValue) && ! array_is_list($baseValue) && array_key_exists($predicate, $baseValue)) {
                    $paths[] = $basePath;
                }

                continue;
            }

            if ($this->isTruthy($predicate)) {
                $paths[] = $basePath;
            }
        }

        return $paths;
    }

    /**
     * @param  array<string, mixed>  $environment
     * @return array<int, array<int, int|string>>
     */
    private function resolveTransformVariablePaths(string $name, array $contextPath, array $environment): array
    {
        if ($name === '$') {
            return [$contextPath];
        }

        $bindings = $environment['__jsonata_transform_paths'] ?? [];

        return is_array($bindings) && array_key_exists($name, $bindings)
            ? $bindings[$name]
            : [];
    }

    /**
     * @param  array<string, mixed>  $ast
     * @param  array<string, mixed>  $environment
     * @return array<int, array<int, int|string>>
     */
    private function resolveTransformSequencePaths(array $ast, mixed &$root, array $contextPath, array &$environment, mixed $rootContext): array
    {
        $localEnvironment = $environment;
        $paths = [];

        foreach ($ast['expressions'] as $expression) {
            $paths = $this->resolveTransformPaths($expression, $root, $contextPath, $localEnvironment, $rootContext);
        }

        return $paths;
    }

    /**
     * @param  array<string, mixed>  $ast
     * @param  array<string, mixed>  $environment
     * @return array<int, array<int, int|string>>
     */
    private function resolveTransformAssignmentPaths(array $ast, mixed &$root, array $contextPath, array &$environment, mixed $rootContext): array
    {
        if (($ast['target']['type'] ?? null) !== 'variable') {
            return [];
        }

        $paths = $this->resolveTransformPaths($ast['value'], $root, $contextPath, $environment, $rootContext);
        $environment['__jsonata_transform_paths'] ??= [];
        $environment['__jsonata_transform_paths'][(string) $ast['target']['name']] = $paths;

        return $paths;
    }

    /**
     * @param  array<string, mixed>  $ast
     * @param  array<string, mixed>  $environment
     * @return array<int, array<int, int|string>>
     */
    private function resolveTransformCallPaths(array $ast, mixed &$root, array $contextPath, array &$environment, mixed $rootContext): array
    {
        if (($ast['callee']['type'] ?? null) !== 'variable' || ($ast['callee']['name'] ?? null) !== '$lookup') {
            return [];
        }

        $arguments = $ast['arguments'] ?? [];
        if (count($arguments) < 2 || ($arguments[1]['type'] ?? null) !== 'literal' || ! is_string($arguments[1]['value'] ?? null)) {
            return [];
        }

        $propertyName = $arguments[1]['value'];
        $basePaths = $this->resolveTransformPaths($arguments[0], $root, $contextPath, $environment, $rootContext);
        $paths = [];

        foreach ($basePaths as $basePath) {
            $baseValue = $this->valueAtPath($root, $basePath);

            if (is_array($baseValue) && array_is_list($baseValue)) {
                foreach ($baseValue as $index => $item) {
                    if (is_array($item) && array_key_exists($propertyName, $item)) {
                        $paths[] = [...$basePath, $index, $propertyName];
                    }
                }

                continue;
            }

            if (is_array($baseValue) && array_key_exists($propertyName, $baseValue)) {
                $paths[] = [...$basePath, $propertyName];
            }
        }

        return $paths;
    }

    /**
     * @param  array<int, int|string>  $basePath
     * @return array<int, array<int, int|string>>
     */
    private function wildcardChildPaths(mixed $value, array $basePath): array
    {
        if (! is_array($value)) {
            return [];
        }

        $paths = [];

        if (array_is_list($value)) {
            foreach ($value as $index => $item) {
                foreach ($this->wildcardChildPaths($item, [...$basePath, $index]) as $path) {
                    $paths[] = $path;
                }
            }

            return $paths;
        }

        foreach (array_keys($value) as $key) {
            $paths[] = [...$basePath, $key];
        }

        return $paths;
    }

    /**
     * @param  array<int, int|string>  $basePath
     * @return array<int, array<int, int|string>>
     */
    private function descendantPaths(mixed $value, array $basePath): array
    {
        $paths = [$basePath];

        if (! is_array($value)) {
            return $paths;
        }

        foreach ($value as $key => $child) {
            foreach ($this->descendantPaths($child, [...$basePath, $key]) as $path) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /**
     * @param  array<int, int|string>  $path
     */
    private function valueAtPath(mixed $root, array $path): mixed
    {
        $value = $root;

        foreach ($path as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return $this->missingValue;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * @param  array<int, int|string>  $path
     */
    private function &referenceAtPath(mixed &$root, array $path): mixed
    {
        $reference = &$root;

        foreach ($path as $segment) {
            $reference = &$reference[$segment];
        }

        return $reference;
    }

    /**
     * @param  array<string, mixed>  $ast
     * @return array<int, string>
     */
    private function normalizeTransformDeleteKeys(mixed $deletions, array $ast): array
    {
        if (is_string($deletions)) {
            return [$deletions];
        }

        if (is_array($deletions) && array_is_list($deletions)) {
            foreach ($deletions as $value) {
                if (! is_string($value)) {
                    throw new EvaluationException(
                        sprintf(
                            'Error T2012: The delete clause of the transform expression must evaluate to a string or array of strings: %s',
                            $this->stringify($deletions)
                        ),
                        'T2012',
                        (int) ($ast['position'] ?? 0)
                    );
                }
            }

            return $deletions;
        }

        throw new EvaluationException(
            sprintf(
                'Error T2012: The delete clause of the transform expression must evaluate to a string or array of strings: %s',
                $this->stringify($deletions)
            ),
            'T2012',
            (int) ($ast['position'] ?? 0)
        );
    }

    /**
     * @param  array<int, array<int, int|string>>  $paths
     * @return array<int, array<int, int|string>>
     */
    private function normalizeTransformMatchPaths(array $paths, mixed &$root): array
    {
        $normalized = [];

        foreach ($paths as $path) {
            $value = $this->valueAtPath($root, $path);

            if ($path === [] && $value === []) {
                $normalized[] = $path;

                continue;
            }

            if (is_array($value) && array_is_list($value)) {
                foreach (array_keys($value) as $index) {
                    $normalized[] = [...$path, $index];
                }

                continue;
            }

            $normalized[] = $path;
        }

        return $normalized;
    }

    private function compareSortValues(mixed $left, mixed $right): int
    {
        $left = $this->tupleValue($left);
        $right = $this->tupleValue($right);

        if ($this->isMissing($left) && $this->isMissing($right)) {
            return 0;
        }

        if ($this->isMissing($left)) {
            return 1;
        }

        if ($this->isMissing($right)) {
            return -1;
        }

        $leftIsNumber = is_int($left) || is_float($left);
        $leftIsString = is_string($left);
        $rightIsNumber = is_int($right) || is_float($right);
        $rightIsString = is_string($right);

        if ((! $leftIsNumber && ! $leftIsString) || (! $rightIsNumber && ! $rightIsString)) {
            throw new EvaluationException(
                'Error T2008: The expressions within an order-by clause must evaluate to numeric or string values',
                'T2008'
            );
        }

        if (($leftIsNumber && $rightIsString) || ($leftIsString && $rightIsNumber)) {
            throw new EvaluationException(
                sprintf(
                    'Error T2007: Type mismatch when comparing values %s and %s in order-by clause',
                    $this->stringify($left),
                    $this->stringify($right)
                ),
                'T2007'
            );
        }

        if ($leftIsNumber && $rightIsNumber) {
            return $left <=> $right;
        }

        return strcmp($left, $right);
    }

    private function compareValues(mixed $left, mixed $right): bool
    {
        if ($this->isMissing($left) || $this->isMissing($right)) {
            return false;
        }

        if ($this->isTupleSequence($left) && $this->isTupleSequence($right)) {
            foreach ($left as $leftItem) {
                foreach ($right as $rightItem) {
                    if ($this->deepEquals($this->unwrapTuples($leftItem), $this->unwrapTuples($rightItem))) {
                        return true;
                    }
                }
            }

            return false;
        }

        $left = $this->normalizePreservingMissingPublic($left);
        $right = $this->normalizePreservingMissingPublic($right);

        if ($this->isMissing($left) || $this->isMissing($right)) {
            return false;
        }

        $left = $this->unwrapTuples($left);
        $right = $this->unwrapTuples($right);

        return $this->deepEquals($left, $right);
    }

    private function deepEquals(mixed $left, mixed $right): bool
    {
        if ($left === null || $right === null) {
            return $left === $right;
        }

        if ((is_int($left) || is_float($left)) && (is_int($right) || is_float($right))) {
            return $left == $right;
        }

        if (is_bool($left) || is_bool($right) || is_string($left) || is_string($right)) {
            return $left === $right;
        }

        if (is_array($left) && is_array($right)) {
            $leftIsList = array_is_list($left);
            $rightIsList = array_is_list($right);

            if ($leftIsList !== $rightIsList || count($left) !== count($right)) {
                return false;
            }

            if ($leftIsList) {
                foreach ($left as $index => $value) {
                    if (! $this->deepEquals($value, $right[$index] ?? null)) {
                        return false;
                    }
                }

                return true;
            }

            ksort($left);
            ksort($right);

            foreach ($left as $key => $value) {
                if (! array_key_exists($key, $right) || ! $this->deepEquals($value, $right[$key])) {
                    return false;
                }
            }

            return true;
        }

        return $left === $right;
    }

    private function isTupleSequence(mixed $value): bool
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return false;
        }

        foreach ($value as $item) {
            if ($this->isTuple($item)) {
                return true;
            }
        }

        return false;
    }

    private function compareNumbers(mixed $left, mixed $right, string $operator): bool
    {
        $left = $this->normalizePreservingMissingPublic($left);
        $right = $this->normalizePreservingMissingPublic($right);

        if ($this->isMissing($left)) {
            return false;
        }

        $leftIsNumber = is_int($left) || is_float($left);
        $leftIsString = is_string($left);

        if (! $leftIsNumber && ! $leftIsString) {
            throw new EvaluationException(
                sprintf('Error T2010: The expressions either side of operator "%s" must evaluate to numeric or string values.', $operator),
                'T2010'
            );
        }

        if ($this->isMissing($right)) {
            return false;
        }

        $rightIsNumber = is_int($right) || is_float($right);
        $rightIsString = is_string($right);

        if (($leftIsNumber && $rightIsString) || ($leftIsString && $rightIsNumber)) {
            throw new EvaluationException(
                sprintf('Error T2009: The values either side of operator "%s" must be of the same data type.', $operator),
                'T2009'
            );
        }

        if (! $rightIsNumber && ! $rightIsString) {
            throw new EvaluationException(
                sprintf('Error T2010: The expressions either side of operator "%s" must evaluate to numeric or string values.', $operator),
                'T2010'
            );
        }

        $comparison = $leftIsString && $rightIsString
            ? strcmp($left, $right)
            : ($left <=> $right);

        return match ($operator) {
            '<' => $comparison < 0,
            '<=' => $comparison <= 0,
            '>' => $comparison > 0,
            '>=' => $comparison >= 0,
            default => false,
        };
    }

    private function evaluateNumericBinary(mixed $left, mixed $right, string $operator): mixed
    {
        $left = $this->normalizePreservingMissingPublic($left);
        $right = $this->normalizePreservingMissingPublic($right);

        if ($this->isMissing($left)) {
            return $this->missingValue;
        }

        if (! is_int($left) && ! is_float($left)) {
            throw new EvaluationException(
                sprintf('Error T2001: The left side of the "%s" operator must evaluate to a number.', $operator),
                'T2001'
            );
        }

        if (is_float($left) && (is_infinite($left) || is_nan($left))) {
            throw new EvaluationException(
                'Error D1001: Number out of range.',
                'D1001'
            );
        }

        if ($this->isMissing($right)) {
            return $this->missingValue;
        }

        if (! is_int($right) && ! is_float($right)) {
            throw new EvaluationException(
                sprintf('Error T2002: The right side of the "%s" operator must evaluate to a number.', $operator),
                'T2002'
            );
        }

        if (is_float($right) && (is_infinite($right) || is_nan($right))) {
            throw new EvaluationException(
                'Error D1001: Number out of range.',
                'D1001'
            );
        }

        $divisionByZero = ($operator === '/' || $operator === '%') && $right == 0;

        $result = match ($operator) {
            '+' => $left + $right,
            '-' => $left - $right,
            '*' => $left * $right,
            '**' => $left ** $right,
            '/' => $right == 0 ? ($left == 0 ? NAN : ($left > 0 ? INF : -INF)) : $left / $right,
            '%' => $right == 0 ? NAN : fmod($left, $right),
            default => throw new EvaluationException(
                sprintf('Unsupported JSONata numeric operator [%s].', $operator)
            ),
        };

        if (! $divisionByZero && is_float($result) && (is_infinite($result) || is_nan($result))) {
            throw new EvaluationException(
                'Error D1001: Number out of range.',
                'D1001'
            );
        }

        return $result;
    }

    private function inSequence(mixed $left, mixed $right): bool
    {
        $left = $this->normalizeValuePublic($left);
        $right = $this->normalizeValuePublic($right);

        foreach ($this->toSequence($right) as $candidate) {
            if ($this->compareValues($left, $candidate)) {
                return true;
            }
        }

        return false;
    }

    private function isTruthy(mixed $value): bool
    {
        $value = $this->normalizeValuePublic($value);

        if ($this->isMissing($value) || $value === null) {
            return false;
        }

        $value = $this->unwrapTuples($value);

        if (is_bool($value)) {
            return $value;
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                foreach ($value as $item) {
                    if ($this->isTruthy($item)) {
                        return true;
                    }
                }

                return false;
            }

            return $value !== [];
        }

        if ($value instanceof stdClass && get_object_vars($value) === []) {
            return false;
        }

        if ($value instanceof Closure) {
            return false;
        }

        if (is_string($value)) {
            return $value !== '';
        }

        return (bool) $value;
    }

    public function isTruthyPublic(mixed $value): bool
    {
        return $this->isTruthy($value);
    }

    private function stringify(mixed $value, bool $prettify = false): string
    {
        if ($this->isMissing($value)) {
            return '';
        }

        $value = $this->normalizeValuePublic($value);

        if ($this->isMissing($value)) {
            return '';
        }

        $value = $this->unwrapTuples($value);

        if ($value === null) {
            return 'null';
        }

        if (is_string($value)) {
            return $value;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value)) {
            return $this->stringifyNumber($value);
        }

        if (is_float($value)) {
            if (is_infinite($value) || is_nan($value)) {
                throw new EvaluationException(
                    'Error D3001: Attempting to invoke string function on Infinity or NaN',
                    'D3001'
                );
            }

            return $this->stringifyNumber($value);
        }

        if ($value instanceof Closure) {
            return '';
        }

        $encoded = json_encode(
            $this->normalizeForJsonString($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | ($prettify ? JSON_PRETTY_PRINT : 0)
        );

        if ($prettify && $encoded !== false) {
            $encoded = preg_replace_callback(
                '/^( +)/m',
                static fn (array $match): string => str_repeat(' ', (int) (strlen($match[1]) / 2)),
                $encoded
            ) ?? $encoded;
        }

        return $encoded === false ? '' : $encoded;
    }

    private function stringifyNumber(int|float $value): string
    {
        $rounded = (float) sprintf('%.14E', (float) $value);

        return $this->jsonEncodeNumber($rounded);
    }

    private function jsonEncodeNumber(float $value): string
    {
        $absolute = abs($value);

        if ($value !== 0.0 && ($absolute >= 1.0e21 || $absolute < 1.0e-6)) {
            return preg_replace(
                ['/(\\.\\d*?)0+e/', '/\\.e/', '/e([+-])0*(\\d+)/'],
                ['$1e', 'e', 'e$1$2'],
                strtolower(sprintf('%.14E', $value))
            ) ?? strtolower(sprintf('%.14E', $value));
        }

        $decimals = max(0, 15 - (int) floor(log10($absolute === 0.0 ? 1.0 : $absolute)) - 1);
        $string = number_format($value, $decimals, '.', '');

        return str_contains($string, '.')
            ? rtrim(rtrim($string, '0'), '.')
            : $string;
    }

    public function stringifyPublic(mixed $value, bool $prettify = false): string
    {
        return $this->stringify($value, $prettify);
    }

    private function normalizeForJsonString(mixed $value): mixed
    {
        if ($value instanceof Closure) {
            return '';
        }

        if (is_float($value) && (is_infinite($value) || is_nan($value))) {
            throw new EvaluationException(
                'Error D1001: Number out of range.',
                'D1001'
            );
        }

        if (is_int($value) || is_float($value)) {
            return (float) sprintf('%.14E', (float) $value);
        }

        if (! is_array($value)) {
            return $value;
        }

        $normalized = [];

        foreach ($value as $key => $item) {
            $normalized[$key] = $this->normalizeForJsonString($item);
        }

        return $normalized;
    }

    private function toNumber(mixed $value): int|float
    {
        $value = $this->normalizeValuePublic($value);

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
            sprintf('Error T2001: Cannot convert value [%s] to a number.', $this->stringify($value)),
            'T2001'
        );
    }

    private function buildRange(mixed $left, mixed $right): mixed
    {
        $bounds = $this->rangeBounds($left, $right);

        if ($bounds === null) {
            return $this->missingValue;
        }

        [$start, $end] = $bounds;

        return range($start, $end);
    }

    /**
     * @param  array<string, mixed>  $ast
     */
    private function isRangeCountChain(array $ast): bool
    {
        return ($ast['left']['type'] ?? null) === 'array'
            && count($ast['left']['items'] ?? []) === 1
            && ($ast['left']['items'][0]['type'] ?? null) === 'binary'
            && ($ast['left']['items'][0]['operator'] ?? null) === '..'
            && ($ast['right']['type'] ?? null) === 'call'
            && ($ast['right']['callee']['type'] ?? null) === 'variable'
            && ($ast['right']['callee']['name'] ?? null) === '$count'
            && ($ast['right']['arguments'] ?? []) === [];
    }

    /**
     * @param  array<string, mixed>  $range
     * @param  array<string, mixed>  $environment
     */
    private function evaluateRangeCountChain(array $range, mixed $context, array &$environment, mixed $rootContext): int
    {
        $bounds = $this->rangeBounds(
            $this->evaluateAst($range['left'], $context, $environment, $rootContext),
            $this->evaluateAst($range['right'], $context, $environment, $rootContext)
        );

        if ($bounds === null) {
            return 0;
        }

        return $bounds[1] - $bounds[0] + 1;
    }

    /**
     * @return array{int, int}|null
     */
    private function rangeBounds(mixed $left, mixed $right): ?array
    {
        $start = $this->rangeEndpoint($left, 'left');
        $end = $this->rangeEndpoint($right, 'right');

        if ($start === null || $end === null || $start > $end) {
            return null;
        }

        $size = $end - $start + 1;
        if ($size > 1e7) {
            throw new EvaluationException(
                sprintf('Error D2014: The size of the sequence allocated by the range operator (..) must not exceed 1e6.  Attempted to allocate %d.', $size),
                'D2014'
            );
        }

        return [$start, $end];
    }

    private function rangeEndpoint(mixed $value, string $side): ?int
    {
        $value = $this->normalizePreservingMissingPublic($value);

        if ($this->isMissing($value)) {
            return null;
        }

        if (! is_int($value) && (! is_float($value) || floor($value) !== $value)) {
            $code = $side === 'left' ? 'T2003' : 'T2004';
            $label = $side === 'left' ? 'left' : 'right';

            throw new EvaluationException(
                sprintf('Error %s: The %s side of the range operator (..) must evaluate to an integer', $code, $label),
                $code
            );
        }

        return (int) $value;
    }

    /**
     * @param  array<string, mixed>  $ast
     * @param  array<string, mixed>  $environment
     */
    private function evaluateBind(array $ast, mixed $context, array &$environment, mixed $rootContext): mixed
    {
        $target = $this->evaluateAst($ast['target'], $context, $environment, $rootContext);
        $items = (($ast['target']['type'] ?? null) === 'property' && ($ast['target']['name'] ?? null) === '$')
            ? [$target]
            : $this->pathInputSequence($target);
        $results = [];

        foreach ($this->bindingGroups($items, (string) $ast['kind'], $ast['target']) as $group) {
            foreach ($group as $index => $item) {
                $bindings = $this->isTuple($item) ? $this->tupleBindings($item) : [];
                $value = $this->tupleValue($item);
                $bindings[(string) $ast['name']] = $ast['kind'] === 'focus' ? $value : $index;

                $results[] = $this->makeTuple(
                    $value,
                    $bindings,
                    $this->tupleParent($item),
                    $ast['kind'] === 'focus' && $this->isTuple($item)
                        ? $this->tupleParent($item)
                        : $this->tupleLookupParent($item),
                    $ast['kind'] === 'focus' ? null : $this->tupleParentContextParent($item)
                );
            }
        }

        return $this->collapseSequence($results);
    }

    /**
     * @param  array<int, mixed>  $items
     * @return array<int, array<int, mixed>>
     */
    private function bindingGroups(array $items, string $kind, array $target): array
    {
        if ($kind !== 'index') {
            return [$items];
        }

        if (in_array($target['type'] ?? null, ['filter', 'subscript', 'sort'], true)) {
            return [$items];
        }

        $excludedBinding = (($target['type'] ?? null) === 'bind' && ($target['kind'] ?? null) === 'focus')
            ? (string) ($target['name'] ?? '')
            : null;

        $groups = [];
        $currentGroup = [];
        $currentKey = null;
        $hasCurrentKey = false;

        foreach ($items as $item) {
            $bindings = $this->tupleBindings($item);
            if ($excludedBinding !== null && $excludedBinding !== '') {
                unset($bindings[$excludedBinding]);
            }

            $key = $this->isTuple($item)
                ? serialize([$this->tupleParent($item), $bindings])
                : '__jsonata_scalar_sequence';

            if ($hasCurrentKey && $key === $currentKey) {
                $currentGroup[] = $item;

                continue;
            }

            if ($currentGroup !== []) {
                $groups[] = $currentGroup;
            }

            $currentGroup = [$item];
            $currentKey = $key;
            $hasCurrentKey = true;
        }

        if ($currentGroup !== []) {
            $groups[] = $currentGroup;
        }

        return $groups;
    }

    /**
     * @param  array<string, mixed>  $bindings
     */
    private function makeTuple(
        mixed $value,
        array $bindings,
        mixed $parent = null,
        mixed $lookupParent = null,
        mixed $parentContextParent = null
    ): array {
        return [
            '__jsonata_tuple' => true,
            'value' => $value,
            'bindings' => $bindings,
            'parent' => $parent,
            'lookup_parent' => $lookupParent,
            'parent_context_parent' => $parentContextParent,
        ];
    }

    private function isTuple(mixed $value): bool
    {
        return is_array($value)
            && ($value['__jsonata_tuple'] ?? false) === true
            && array_key_exists('bindings', $value)
            && array_key_exists('value', $value);
    }

    /**
     * @return array<string, mixed>
     */
    private function tupleBindings(mixed $value): array
    {
        return $this->isTuple($value) ? $value['bindings'] : [];
    }

    private function tupleParent(mixed $value): mixed
    {
        return $this->isTuple($value) ? ($value['parent'] ?? null) : null;
    }

    private function tupleLookupParent(mixed $value): mixed
    {
        return $this->isTuple($value) ? ($value['lookup_parent'] ?? null) : null;
    }

    private function tupleParentContextParent(mixed $value): mixed
    {
        return $this->isTuple($value) ? ($value['parent_context_parent'] ?? null) : null;
    }

    private function tupleValue(mixed $value): mixed
    {
        return $this->isTuple($value) ? $value['value'] : $value;
    }

    /**
     * @param  array<string, mixed>  $bindings
     */
    private function wrapTupleResult(
        mixed $value,
        array $bindings,
        mixed $parent = null,
        mixed $parentContextParent = null
    ): mixed {
        if ($this->isMissing($value) || $value === null) {
            return $value;
        }

        if (is_array($value) && array_is_list($value)) {
            $results = [];

            foreach ($value as $item) {
                $results[] = $this->makeTuple($this->tupleValue($item), [
                    ...$bindings,
                    ...$this->tupleBindings($item),
                ], $parent ?? $this->tupleParent($item), $this->tupleLookupParent($item), $parentContextParent ?? $this->tupleParentContextParent($item));
            }

            return $this->collapseSequence($results);
        }

        return $this->makeTuple($this->tupleValue($value), [
            ...$bindings,
            ...$this->tupleBindings($value),
        ], $parent ?? $this->tupleParent($value), $this->tupleLookupParent($value), $parentContextParent ?? $this->tupleParentContextParent($value));
    }

    private function unwrapTuples(mixed $value): mixed
    {
        if ($this->isTuple($value)) {
            return $this->unwrapTuples($this->tupleValue($value));
        }

        if ($value instanceof stdClass && get_object_vars($value) === []) {
            return [];
        }

        if (! is_array($value)) {
            return $value;
        }

        $result = [];

        foreach ($value as $key => $item) {
            $result[$key] = $this->unwrapTuples($item);
        }

        return $result;
    }
}
