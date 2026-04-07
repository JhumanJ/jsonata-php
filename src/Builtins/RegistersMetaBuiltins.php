<?php

namespace JsonataPhp\Builtins;

use JsonataPhp\EvaluationException;
use JsonataPhp\Evaluator;

trait RegistersMetaBuiltins
{
    /**
     * @param  array<string, mixed>  $rootContext
     * @return array<int, BuiltinDefinition>
     */
    protected function metaBuiltinDefinitions(Evaluator $evaluator, array $rootContext): array
    {
        return [
            $this->builtin('exists', fn (array $arguments): bool => array_key_exists(0, $arguments) && ! $this->support->isMissingLike($arguments[0], $evaluator), '<x:b>'),
            $this->builtin('boolean', fn (array $arguments): bool => $evaluator->isTruthyPublic($arguments[0] ?? null), '<x-:b>'),
            $this->builtin('not', fn (array $arguments): bool => ! $evaluator->isTruthyPublic($arguments[0] ?? null), '<x-:b>'),
            $this->builtin('type', function (array $arguments) use ($evaluator): ?string {
                $type = $this->jsonTypeSymbol($arguments[0] ?? null, $evaluator);

                return $type === 'missing' ? null : $type;
            }, '<x:s>'),
            $this->builtin('error', function (array $arguments): never {
                throw new EvaluationException(
                    sprintf('Error D3137: %s', $arguments[0] ?? '$error() function evaluated'),
                    'D3137'
                );
            }, '<s?:x>'),
            $this->builtin('assert', function (array $arguments) use ($evaluator): mixed {
                if ($evaluator->isTruthyPublic($arguments[0] ?? null)) {
                    return null;
                }

                throw new EvaluationException(
                    sprintf('Error D3141: %s', $arguments[1] ?? '$assert() statement failed'),
                    'D3141'
                );
            }, '<bs?:x>'),
            $this->builtin('eval', function (array $arguments, mixed $context) use ($evaluator): mixed {
                $expression = (string) ($arguments[0] ?? '');
                $focus = $arguments[1] ?? $context;

                try {
                    return $this->evaluateInline($expression, $focus, $evaluator);
                } catch (EvaluationException $exception) {
                    throw new EvaluationException(
                        sprintf('Error D3121: Dynamic error evaluating the expression passed to function eval: %s', $exception->getMessage()),
                        'D3121',
                        $exception->position,
                        $exception->details
                    );
                }
            }, '<sx?:x>'),
        ];
    }
}
