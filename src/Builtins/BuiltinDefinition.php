<?php

namespace JsonataPhp\Builtins;

use Closure;
use JsonataPhp\Evaluator;

class BuiltinDefinition
{
    private ?Signature $parsedSignature;

    public function __construct(
        public readonly string $name,
        public readonly Closure $implementation,
        ?string $signature = null,
    ) {
        $this->parsedSignature = $signature === null ? null : Signature::parse($signature);
    }

    public function invoke(array $arguments, mixed $context, Evaluator $evaluator): mixed
    {
        $arguments = array_map(
            fn (mixed $argument): mixed => $evaluator->normalizePreservingMissingPublic($argument),
            $arguments
        );
        $context = $evaluator->normalizePreservingMissingPublic($context);
        $validatedArguments = $this->parsedSignature?->validate($arguments, $context, $evaluator) ?? $arguments;

        if ($this->returnsMissingWhenAnyArgumentIsMissing($validatedArguments, $evaluator)) {
            return $evaluator->missingValuePublic();
        }

        $validatedArguments = array_map(
            fn (mixed $argument): mixed => $this->preservesMissingArguments()
                ? $evaluator->normalizePreservingMissingPublic($argument)
                : $evaluator->normalizeValuePublic($argument),
            $validatedArguments
        );
        $context = $evaluator->normalizeValuePublic($context);

        return ($this->implementation)($validatedArguments, $context);
    }

    public function arity(): ?int
    {
        return $this->parsedSignature?->parameterCount();
    }

    /**
     * @param  array<int, mixed>  $arguments
     */
    private function returnsMissingWhenAnyArgumentIsMissing(array $arguments, Evaluator $evaluator): bool
    {
        if ($this->preservesMissingArguments()) {
            return false;
        }

        foreach ($arguments as $argument) {
            if ($evaluator->isMissing($argument)) {
                return true;
            }
        }

        return false;
    }

    private function preservesMissingArguments(): bool
    {
        return in_array($this->name, ['boolean', 'count', 'error', 'exists', 'join', 'not', 'type'], true);
    }
}
