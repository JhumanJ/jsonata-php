<?php

namespace JsonataPhp;

use Closure;
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
     * @param  array<string, mixed>  $rootContext
     */
    public function evaluate(array $ast, array $rootContext): mixed
    {
        return $this->evaluateWithContext($ast, $rootContext, $rootContext);
    }

    /**
     * @param  array<string, mixed>  $ast
     * @param  array<string, mixed>  $rootContext
     */
    public function evaluateWithContext(array $ast, mixed $context, array $rootContext): mixed
    {
        $environment = $this->functions->defaultEnvironment($this, $rootContext);
        $result = $this->evaluateAst($ast, $context, $environment, $rootContext);

        return $this->isMissing($result) ? null : $this->unwrapTuples($result);
    }

    public function isMissing(mixed $value): bool
    {
        return $value === $this->missingValue;
    }

    public function normalizeValuePublic(mixed $value): mixed
    {
        if ($this->isMissing($value)) {
            return null;
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
     * @param  array<string, mixed>  $rootContext
     */
    private function evaluateAst(array $ast, mixed $context, array &$environment, array $rootContext): mixed
    {
        return match ($ast['type']) {
            'literal' => $this->normalizeLiteral($ast['value']),
            'identifier' => $this->resolveIdentifier((string) $ast['name'], $context),
            'variable' => $this->resolveVariable((string) $ast['name'], $context, $environment, $rootContext),
            'bind' => $this->evaluateBind($ast, $context, $environment, $rootContext),
            'sequence' => $this->evaluateSequence($ast, $context, $environment, $rootContext),
            'assignment' => $this->evaluateAssignment($ast, $context, $environment, $rootContext),
            'conditional' => $this->evaluateConditional($ast, $context, $environment, $rootContext),
            'unary' => $this->evaluateUnary($ast, $context, $environment, $rootContext),
            'binary' => $this->evaluateBinary($ast, $context, $environment, $rootContext),
            'property' => $this->accessProperty(
                $this->evaluateAst($ast['target'], $context, $environment, $rootContext),
                (string) $ast['name']
            ),
            'wildcard' => $this->evaluateWildcard(
                $this->evaluateAst($ast['target'], $context, $environment, $rootContext)
            ),
            'wildcard_context' => $this->evaluateWildcard($context),
            'descendant' => $this->evaluateDescendant(
                $this->evaluateAst($ast['target'], $context, $environment, $rootContext)
            ),
            'descendant_context' => $this->evaluateDescendant($context),
            'parent' => $this->evaluateParent($ast, $context, $environment, $rootContext),
            'subscript' => $this->accessSubscript(
                $this->evaluateAst($ast['target'], $context, $environment, $rootContext),
                $this->evaluateAst($ast['index'], $context, $environment, $rootContext)
            ),
            'filter' => $this->filterSequence($ast, $context, $environment, $rootContext),
            'sort' => $this->evaluateSort($ast, $context, $environment, $rootContext),
            'object_map' => $this->evaluateObjectMap($ast, $context, $environment, $rootContext),
            'group' => $this->evaluateGroup($ast, $context, $environment, $rootContext),
            'array' => $this->evaluateArrayLiteral($ast, $context, $environment, $rootContext),
            'object' => $this->evaluateObjectLiteral($ast, $context, $environment, $rootContext),
            'function' => $this->createClosure($ast, $environment, $rootContext),
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
     * @param  array<string, mixed>  $rootContext
     */
    private function evaluateSequence(array $ast, mixed $context, array &$environment, array $rootContext): mixed
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
     * @param  array<string, mixed>  $rootContext
     */
    private function evaluateAssignment(array $ast, mixed $context, array &$environment, array $rootContext): mixed
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
     * @param  array<string, mixed>  $rootContext
     */
    private function resolveVariable(string $name, mixed $context, array $environment, array $rootContext): mixed
    {
        if ($this->isTuple($context)) {
            $binding = $this->tupleBindings($context)[$name] ?? null;
            if ($binding !== null || array_key_exists($name, $this->tupleBindings($context))) {
                return $binding;
            }
        }

        return match ($name) {
            '$' => $this->tupleValue($context),
            '$$' => $rootContext,
            default => $environment[$name] ?? $this->missingValue,
        };
    }

    /**
     * @param  array<string, mixed>  $ast
     * @param  array<string, mixed>  $environment
     * @param  array<string, mixed>  $rootContext
     */
    private function evaluateConditional(array $ast, mixed $context, array &$environment, array $rootContext): mixed
    {
        $test = $this->evaluateAst($ast['test'], $context, $environment, $rootContext);

        if ($this->isTruthy($test)) {
            return $this->evaluateAst($ast['consequent'], $context, $environment, $rootContext);
        }

        return $this->evaluateAst($ast['alternate'], $context, $environment, $rootContext);
    }

    /**
     * @param  array<string, mixed>  $ast
     * @param  array<string, mixed>  $environment
     * @param  array<string, mixed>  $rootContext
     */
    private function evaluateUnary(array $ast, mixed $context, array &$environment, array $rootContext): mixed
    {
        $value = $this->evaluateAst($ast['argument'], $context, $environment, $rootContext);

        if ($this->isMissing($value) || $value === null) {
            return $this->missingValue;
        }

        return match ($ast['operator']) {
            '+' => $this->toNumber($value),
            '-' => -$this->toNumber($value),
            default => throw new EvaluationException(
                sprintf('Unsupported JSONata unary operator [%s].', (string) $ast['operator'])
            ),
        };
    }

    /**
     * @param  array<string, mixed>  $ast
     * @param  array<string, mixed>  $environment
     * @param  array<string, mixed>  $rootContext
     */
    private function evaluateBinary(array $ast, mixed $context, array &$environment, array $rootContext): mixed
    {
        $left = $this->evaluateAst($ast['left'], $context, $environment, $rootContext);

        if ($ast['operator'] === '~>') {
            return $this->evaluateChain($ast['right'], $left, $context, $environment, $rootContext);
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
            '??' => (! $this->isMissing($left) && $left !== null) ? $left : $right,
            '?:' => $this->isTruthy($left) ? $left : $right,
            '+' => $this->toNumber($left) + $this->toNumber($right),
            '-' => $this->toNumber($left) - $this->toNumber($right),
            '*' => $this->toNumber($left) * $this->toNumber($right),
            '**' => $this->toNumber($left) ** $this->toNumber($right),
            '/' => $this->toNumber($left) / $this->toNumber($right),
            '%' => fmod($this->toNumber($left), $this->toNumber($right)),
            'and' => $this->isTruthy($left) && $this->isTruthy($right),
            'or' => $this->isTruthy($left) || $this->isTruthy($right),
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
     * @param  array<string, mixed>  $rootContext
     * @return array<int, mixed>
     */
    private function evaluateArrayLiteral(array $ast, mixed $context, array &$environment, array $rootContext): array
    {
        $items = [];

        foreach ($ast['items'] as $item) {
            $value = $this->evaluateAst($item, $context, $environment, $rootContext);
            $items[] = $this->isMissing($value) ? null : $value;
        }

        return $items;
    }

    private function resolveIdentifier(string $name, mixed $context): mixed
    {
        $context = $this->tupleValue($context);

        if (is_array($context) && array_key_exists($name, $context)) {
            return $context[$name];
        }

        return $this->missingValue;
    }

    private function accessProperty(mixed $target, string $name): mixed
    {
        if ($this->isMissing($target) || $target === null) {
            return $this->missingValue;
        }

        if ($this->isTuple($target)) {
            return $this->wrapTupleResult(
                $this->accessProperty($this->tupleValue($target), $name),
                $this->tupleBindings($target)
            );
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

        if (is_array($target) && array_key_exists($name, $target)) {
            return $this->wrapTupleResult($target[$name], []);
        }

        return $this->missingValue;
    }

    private function accessSubscript(mixed $target, mixed $index): mixed
    {
        if ($this->isMissing($target) || $target === null) {
            return $this->missingValue;
        }

        if ($this->isTuple($target)) {
            return $this->wrapTupleResult(
                $this->accessSubscript($this->tupleValue($target), $index),
                $this->tupleBindings($target)
            );
        }

        if (is_int($index) && is_array($target) && array_is_list($target)) {
            return array_key_exists($index, $target)
                ? $this->wrapTupleResult($target[$index], [])
                : $this->missingValue;
        }

        // JSONata allows indexing into a singleton sequence after a path step
        // has already collapsed a one-item array into its only value.
        if (is_int($index)) {
            return $index === 0
                ? $this->wrapTupleResult($target, [])
                : $this->missingValue;
        }

        if (is_string($index) && is_array($target) && array_key_exists($index, $target)) {
            return $this->wrapTupleResult($target[$index], []);
        }

        return $this->missingValue;
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
            return $this->collapseSequence(array_map(
                fn (mixed $value): mixed => $this->wrapTupleResult($value, []),
                array_values($target)
            ));
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
                $this->collectDescendants($item, $results, true, $item);
            }
        } else {
            $this->collectDescendants($target, $results, true, $target);
        }

        return $this->collapseSequence($results);
    }

    /**
     * @param  array<string, mixed>  $ast
     * @param  array<string, mixed>  $environment
     * @param  array<string, mixed>  $rootContext
     */
    private function evaluateParent(array $ast, mixed $context, array &$environment, array $rootContext): mixed
    {
        $parents = $this->collectParentValues($ast['target'], $context, $environment, $rootContext);

        return $this->collapseSequence($parents);
    }

    /**
     * @param  array<string, mixed>  $ast
     * @param  array<string, mixed>  $environment
     * @param  array<string, mixed>  $rootContext
     * @return array<int, mixed>
     */
    private function collectParentValues(array $ast, mixed $context, array &$environment, array $rootContext): array
    {
        return match ($ast['type']) {
            'identifier' => $this->expandParentMatches($context, $this->resolveIdentifier((string) $ast['name'], $context)),
            'variable' => ($ast['name'] ?? null) === '$'
                ? $this->expandParentMatches($context, $this->tupleValue($context))
                : [],
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
     * @param  array<string, mixed>  $rootContext
     * @return array<int, mixed>
     */
    private function collectPropertyParentValues(array $ast, mixed $context, array &$environment, array $rootContext): array
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
     * @param  array<string, mixed>  $rootContext
     * @return array<int, mixed>
     */
    private function collectSubscriptParentValues(array $ast, mixed $context, array &$environment, array $rootContext): array
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
     * @param  array<string, mixed>  $rootContext
     * @return array<int, mixed>
     */
    private function collectWildcardParentValues(array $ast, mixed $context, array &$environment, array $rootContext): array
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
     * @param  array<string, mixed>  $rootContext
     * @return array<int, mixed>
     */
    private function collectFilterParentValues(array $ast, mixed $context, array &$environment, array $rootContext): array
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

        if ($includeSelf) {
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
     * @param  array<string, mixed>  $rootContext
     */
    private function filterSequence(array $ast, mixed $context, array &$environment, array $rootContext): mixed
    {
        $sequence = $this->evaluateAst($ast['target'], $context, $environment, $rootContext);
        $items = $this->toSequence($sequence);

        if ($items === []) {
            return $this->missingValue;
        }

        $matches = [];

        foreach ($items as $item) {
            $predicate = $this->evaluateAst($ast['predicate'], $item, $environment, $rootContext);

            if ($this->isTruthy($predicate)) {
                $matches[] = $item;
            }
        }

        return $this->collapseSequence($matches);
    }

    /**
     * @param  array<string, mixed>  $ast
     * @param  array<string, mixed>  $environment
     * @param  array<string, mixed>  $rootContext
     * @return array<string, mixed>
     */
    private function evaluateObjectLiteral(array $ast, mixed $context, array &$environment, array $rootContext): array
    {
        $object = [];

        foreach ($ast['pairs'] as $pair) {
            $value = $this->evaluateAst($pair['value'], $context, $environment, $rootContext);
            $value = $this->normalizeValuePublic($value);
            $object[$pair['key']] = $this->isMissing($value) ? null : $value;
        }

        return $object;
    }

    /**
     * @param  array<string, mixed>  $ast
     * @param  array<string, mixed>  $environment
     * @param  array<string, mixed>  $rootContext
     * @return array<string, mixed>
     */
    private function evaluateGroup(array $ast, mixed $context, array &$environment, array $rootContext): array
    {
        $sequence = $this->toSequence(
            $this->evaluateAst($ast['target'], $context, $environment, $rootContext)
        );
        $grouped = [];

        foreach ($sequence as $item) {
            foreach ($ast['pairs'] as $pair) {
                $keys = $this->toSequence(
                    $this->evaluateAst($pair['key'], $item, $environment, $rootContext)
                );
                $value = $this->evaluateAst($pair['value'], $item, $environment, $rootContext);

                foreach ($keys as $key) {
                    if ($this->isMissing($key) || $key === null) {
                        continue;
                    }

                    $stringKey = $this->stringify($key);

                    if (! array_key_exists($stringKey, $grouped)) {
                        $grouped[$stringKey] = $value;

                        continue;
                    }

                    if (! is_array($grouped[$stringKey]) || ! array_is_list($grouped[$stringKey])) {
                        $grouped[$stringKey] = [$grouped[$stringKey]];
                    }

                    $grouped[$stringKey][] = $value;
                }
            }
        }

        return $grouped;
    }

    /**
     * @param  array<string, mixed>  $ast
     * @param  array<string, mixed>  $environment
     * @param  array<string, mixed>  $rootContext
     */
    private function createClosure(array $ast, array $environment, array $rootContext): Closure
    {
        $closure = function (array $arguments, mixed $callContext = null) use ($ast, $environment, $rootContext): mixed {
            $localEnvironment = $environment;

            foreach ($ast['parameters'] as $index => $parameter) {
                $localEnvironment[$parameter] = $arguments[$index] ?? null;
            }

            $localContext = $callContext ?? $rootContext;

            return $this->evaluateAst($ast['body'], $localContext, $localEnvironment, $rootContext);
        };

        return $this->functions->registerFunctionArity($closure, count($ast['parameters']));
    }

    /**
     * @param  array<string, mixed>  $ast
     * @param  array<string, mixed>  $environment
     * @param  array<string, mixed>  $rootContext
     */
    private function createTransformClosure(array $ast, array $environment, array $rootContext): Closure
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
                    if (! is_array($update) || array_is_list($update)) {
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

                    foreach ($update as $property => $value) {
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
     * @param  array<string, mixed>  $rootContext
     */
    private function evaluateCall(array $ast, mixed $context, array &$environment, array $rootContext): mixed
    {
        $callee = $this->evaluateAst($ast['callee'], $context, $environment, $rootContext);

        if (! $callee instanceof Closure) {
            throw new EvaluationException(
                'Error T1006: Attempted to call a non-function value.',
                'T1006'
            );
        }

        $arguments = [];

        foreach ($ast['arguments'] as $argument) {
            $arguments[] = $this->normalizeValuePublic(
                $this->evaluateAst($argument, $context, $environment, $rootContext)
            );
        }

        return $callee($arguments, $context);
    }

    /**
     * @param  array<string, mixed>  $ast
     * @param  array<string, mixed>  $environment
     * @param  array<string, mixed>  $rootContext
     */
    private function evaluatePartial(array $ast, mixed $context, array &$environment, array $rootContext): Closure
    {
        $callee = $this->evaluateAst($ast['callee'], $context, $environment, $rootContext);

        if (! $callee instanceof Closure) {
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
     * @param  array<string, mixed>  $rootContext
     */
    private function evaluateChain(array $ast, mixed $input, mixed $context, array &$environment, array $rootContext): mixed
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

        if (($ast['type'] ?? null) === 'call') {
            $callee = $this->evaluateAst($ast['callee'], $context, $environment, $rootContext);

            if (! $callee instanceof Closure) {
                throw new EvaluationException(
                    'Error T2006: The right side of the function application operator ~> must be a function.',
                    'T2006'
                );
            }

            $arguments = [$this->normalizeValuePublic($input)];

            foreach ($ast['arguments'] as $argument) {
                $arguments[] = $this->normalizeValuePublic(
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

            return $callee([$this->normalizeValuePublic($input)], $context);
        }

        $callee = $this->evaluateAst($ast, $context, $environment, $rootContext);

        if (! $callee instanceof Closure) {
            throw new EvaluationException(
                'Error T2006: The right side of the function application operator ~> must be a function.',
                'T2006'
            );
        }

        return $callee([$this->normalizeValuePublic($input)], $context);
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
     * @param  array<string, mixed>  $rootContext
     */
    private function createPartialApplication(
        Closure $callee,
        array $argumentAsts,
        mixed $context,
        array $environment,
        array $rootContext,
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
     * @param  array<string, mixed>  $rootContext
     * @return array{0: array<int, mixed>, 1: int}
     */
    private function resolveCallArguments(
        array $argumentAsts,
        array $providedArguments,
        mixed $context,
        array $environment,
        array $rootContext,
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

            $resolvedArguments[] = $this->normalizeValuePublic(
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
     * @param  array<string, mixed>  $rootContext
     */
    private function evaluateSort(array $ast, mixed $context, array &$environment, array $rootContext): mixed
    {
        $items = $this->toSequence($this->evaluateAst($ast['target'], $context, $environment, $rootContext));
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
     * @param  array<string, mixed>  $rootContext
     */
    private function evaluateObjectMap(array $ast, mixed $context, array &$environment, array $rootContext): mixed
    {
        $items = $this->toSequence($this->evaluateAst($ast['target'], $context, $environment, $rootContext));
        $results = [];

        foreach ($items as $item) {
            $results[] = $this->evaluateObjectLiteral($ast['object'], $item, $environment, $rootContext);
        }

        return $this->collapseSequence($results);
    }

    /**
     * @param  array<string, mixed>  $ast
     * @param  array<int, int|string>  $contextPath
     * @param  array<string, mixed>  $environment
     * @param  array<string, mixed>  $rootContext
     * @return array<int, array<int, int|string>>
     */
    private function resolveTransformPaths(array $ast, mixed &$root, array $contextPath, array &$environment, array $rootContext): array
    {
        return match ($ast['type']) {
            'identifier' => $this->resolveTransformIdentifierPaths((string) $ast['name'], $root, $contextPath),
            'property' => $this->resolveTransformPropertyPaths($ast, $root, $contextPath, $environment, $rootContext),
            'wildcard' => $this->resolveTransformWildcardPaths($ast, $root, $contextPath, $environment, $rootContext),
            'descendant' => $this->resolveTransformDescendantPaths($ast, $root, $contextPath, $environment, $rootContext),
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

        if (! is_array($contextValue) || ! array_key_exists($name, $contextValue)) {
            return [];
        }

        return [[...$contextPath, $name]];
    }

    /**
     * @param  array<string, mixed>  $ast
     * @param  array<string, mixed>  $environment
     * @param  array<string, mixed>  $rootContext
     * @return array<int, array<int, int|string>>
     */
    private function resolveTransformPropertyPaths(array $ast, mixed &$root, array $contextPath, array &$environment, array $rootContext): array
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
     * @param  array<string, mixed>  $rootContext
     * @return array<int, array<int, int|string>>
     */
    private function resolveTransformWildcardPaths(array $ast, mixed &$root, array $contextPath, array &$environment, array $rootContext): array
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
     * @param  array<string, mixed>  $rootContext
     * @return array<int, array<int, int|string>>
     */
    private function resolveTransformDescendantPaths(array $ast, mixed &$root, array $contextPath, array &$environment, array $rootContext): array
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
     * @param  array<string, mixed>  $rootContext
     * @return array<int, array<int, int|string>>
     */
    private function resolveTransformSubscriptPaths(array $ast, mixed &$root, array $contextPath, array &$environment, array $rootContext): array
    {
        $basePaths = $this->resolveTransformPaths($ast['target'], $root, $contextPath, $environment, $rootContext);
        $indexValue = $this->evaluateAst($ast['index'], $root, $environment, $rootContext);
        $paths = [];

        if (! is_int($indexValue)) {
            return [];
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
     * @param  array<string, mixed>  $rootContext
     * @return array<int, array<int, int|string>>
     */
    private function resolveTransformFilterPaths(array $ast, mixed &$root, array $contextPath, array &$environment, array $rootContext): array
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

            if ($this->isTruthy($this->evaluateAst($ast['predicate'], $baseValue, $environment, $rootContext))) {
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
     * @param  array<string, mixed>  $rootContext
     * @return array<int, array<int, int|string>>
     */
    private function resolveTransformSequencePaths(array $ast, mixed &$root, array $contextPath, array &$environment, array $rootContext): array
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
     * @param  array<string, mixed>  $rootContext
     * @return array<int, array<int, int|string>>
     */
    private function resolveTransformAssignmentPaths(array $ast, mixed &$root, array $contextPath, array &$environment, array $rootContext): array
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
     * @param  array<string, mixed>  $rootContext
     * @return array<int, array<int, int|string>>
     */
    private function resolveTransformCallPaths(array $ast, mixed &$root, array $contextPath, array &$environment, array $rootContext): array
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

        if ($this->isMissing($left) || $left === null) {
            return 1;
        }

        if ($this->isMissing($right) || $right === null) {
            return -1;
        }

        if ((is_int($left) || is_float($left)) && (is_int($right) || is_float($right))) {
            return $left <=> $right;
        }

        return strcmp($this->stringify($left), $this->stringify($right));
    }

    private function compareValues(mixed $left, mixed $right): bool
    {
        $left = $this->normalizeValuePublic($left);
        $right = $this->normalizeValuePublic($right);

        if ($this->isMissing($left) || $this->isMissing($right)) {
            return false;
        }

        $left = $this->unwrapTuples($left);
        $right = $this->unwrapTuples($right);

        return $left == $right;
    }

    private function compareNumbers(mixed $left, mixed $right, string $operator): bool
    {
        $left = $this->normalizeValuePublic($left);
        $right = $this->normalizeValuePublic($right);

        if ($this->isMissing($left) || $this->isMissing($right)) {
            return false;
        }

        $left = $this->toNumber($left);
        $right = $this->toNumber($right);

        return match ($operator) {
            '<' => $left < $right,
            '<=' => $left <= $right,
            '>' => $left > $right,
            '>=' => $left >= $right,
            default => false,
        };
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
            return $value !== [];
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

    private function stringify(mixed $value): string
    {
        $value = $this->normalizeValuePublic($value);

        if ($this->isMissing($value) || $value === null) {
            return '';
        }

        $value = $this->unwrapTuples($value);

        if (is_string($value)) {
            return $value;
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? '' : $encoded;
    }

    public function stringifyPublic(mixed $value): string
    {
        return $this->stringify($value);
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

    /**
     * @return array<int, int>
     */
    private function buildRange(mixed $left, mixed $right): array
    {
        $start = (int) $this->toNumber($left);
        $end = (int) $this->toNumber($right);

        return $start <= $end
            ? range($start, $end)
            : range($start, $end, -1);
    }

    /**
     * @param  array<string, mixed>  $ast
     * @param  array<string, mixed>  $environment
     * @param  array<string, mixed>  $rootContext
     */
    private function evaluateBind(array $ast, mixed $context, array &$environment, array $rootContext): mixed
    {
        $items = $this->toSequence($this->evaluateAst($ast['target'], $context, $environment, $rootContext));
        $results = [];

        foreach ($items as $index => $item) {
            $bindings = $this->isTuple($item) ? $this->tupleBindings($item) : [];
            $value = $this->tupleValue($item);
            $bindings[(string) $ast['name']] = $ast['kind'] === 'focus' ? $value : $index;
            $results[] = $this->makeTuple($value, $bindings);
        }

        return $this->collapseSequence($results);
    }

    /**
     * @param  array<string, mixed>  $bindings
     */
    private function makeTuple(mixed $value, array $bindings, mixed $parent = null): array
    {
        return [
            '__jsonata_tuple' => true,
            'value' => $value,
            'bindings' => $bindings,
            'parent' => $parent,
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

    private function tupleValue(mixed $value): mixed
    {
        return $this->isTuple($value) ? $value['value'] : $value;
    }

    /**
     * @param  array<string, mixed>  $bindings
     */
    private function wrapTupleResult(mixed $value, array $bindings, mixed $parent = null): mixed
    {
        if ($this->isMissing($value) || $value === null) {
            return $value;
        }

        if (is_array($value) && array_is_list($value)) {
            $results = [];

            foreach ($value as $item) {
                $results[] = $this->makeTuple($this->tupleValue($item), [
                    ...$bindings,
                    ...$this->tupleBindings($item),
                ], $parent ?? $this->tupleParent($item));
            }

            return $this->collapseSequence($results);
        }

        return $this->makeTuple($this->tupleValue($value), [
            ...$bindings,
            ...$this->tupleBindings($value),
        ], $parent ?? $this->tupleParent($value));
    }

    private function unwrapTuples(mixed $value): mixed
    {
        if ($this->isTuple($value)) {
            return $this->unwrapTuples($this->tupleValue($value));
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
