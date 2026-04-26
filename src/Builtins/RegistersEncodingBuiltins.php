<?php

namespace JsonataPhp\Builtins;

use JsonataPhp\EvaluationException;
use JsonataPhp\Evaluator;

trait RegistersEncodingBuiltins
{
    /**
     * @return array<int, BuiltinDefinition>
     */
    protected function encodingBuiltinDefinitions(Evaluator $evaluator, mixed $rootContext): array
    {
        return [
            $this->builtin('base64encode', fn (array $arguments): string => base64_encode($evaluator->stringifyPublic($arguments[0] ?? '')), '<s-:s>'),
            $this->builtin('base64decode', function (array $arguments) use ($evaluator): string {
                return base64_decode($evaluator->stringifyPublic($arguments[0] ?? ''), true) ?: '';
            }, '<s-:s>'),
            $this->builtin('encodeUrlComponent', function (array $arguments) use ($evaluator): string {
                return $this->encodeUrlString($evaluator->stringifyPublic($arguments[0] ?? ''), 'encodeUrlComponent', true);
            }, '<s-:s>'),
            $this->builtin('decodeUrlComponent', function (array $arguments) use ($evaluator): string {
                return $this->decodeUrlString($evaluator->stringifyPublic($arguments[0] ?? ''), 'decodeUrlComponent');
            }, '<s-:s>'),
            $this->builtin('encodeUrl', function (array $arguments) use ($evaluator): string {
                return $this->encodeUrlString($evaluator->stringifyPublic($arguments[0] ?? ''), 'encodeUrl', false);
            }, '<s-:s>'),
            $this->builtin('decodeUrl', function (array $arguments) use ($evaluator): string {
                return $this->decodeUrlString($evaluator->stringifyPublic($arguments[0] ?? ''), 'decodeUrl');
            }, '<s-:s>'),
        ];
    }

    private function encodeUrlString(string $value, string $functionName, bool $component): string
    {
        if (! mb_check_encoding($value, 'UTF-8')) {
            throw new EvaluationException(
                sprintf('Error D3140: Malformed URL passed to $%s(): "%s"', $functionName, $value),
                'D3140'
            );
        }

        $encoded = rawurlencode($value);

        if ($component) {
            return $encoded;
        }

        return strtr($encoded, [
            '%3A' => ':',
            '%2F' => '/',
            '%3F' => '?',
            '%23' => '#',
            '%5B' => '[',
            '%5D' => ']',
            '%40' => '@',
            '%21' => '!',
            '%24' => '$',
            '%26' => '&',
            '%27' => '\'',
            '%28' => '(',
            '%29' => ')',
            '%2A' => '*',
            '%2B' => '+',
            '%2C' => ',',
            '%3B' => ';',
            '%3D' => '=',
        ]);
    }

    private function decodeUrlString(string $value, string $functionName): string
    {
        $decoded = rawurldecode($value);

        if (! mb_check_encoding($decoded, 'UTF-8')) {
            throw new EvaluationException(
                sprintf('Error D3140: Malformed URL passed to $%s(): "%s"', $functionName, $value),
                'D3140'
            );
        }

        return $decoded;
    }
}
