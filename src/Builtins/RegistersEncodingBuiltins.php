<?php

namespace JsonataPhp\Builtins;

use JsonataPhp\Evaluator;

trait RegistersEncodingBuiltins
{
    /**
     * @param  array<string, mixed>  $rootContext
     * @return array<int, BuiltinDefinition>
     */
    protected function encodingBuiltinDefinitions(Evaluator $evaluator, array $rootContext): array
    {
        return [
            $this->builtin('base64encode', fn (array $arguments): string => base64_encode($evaluator->stringifyPublic($arguments[0] ?? '')), '<s-:s>'),
            $this->builtin('base64decode', function (array $arguments) use ($evaluator): string {
                return base64_decode($evaluator->stringifyPublic($arguments[0] ?? ''), true) ?: '';
            }, '<s-:s>'),
            $this->builtin('encodeUrlComponent', fn (array $arguments): string => rawurlencode($evaluator->stringifyPublic($arguments[0] ?? '')), '<s-:s>'),
            $this->builtin('decodeUrlComponent', fn (array $arguments): string => rawurldecode($evaluator->stringifyPublic($arguments[0] ?? '')), '<s-:s>'),
            $this->builtin('encodeUrl', function (array $arguments) use ($evaluator): string {
                $encoded = rawurlencode($evaluator->stringifyPublic($arguments[0] ?? ''));

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
            }, '<s-:s>'),
            $this->builtin('decodeUrl', fn (array $arguments): string => rawurldecode($evaluator->stringifyPublic($arguments[0] ?? '')), '<s-:s>'),
        ];
    }
}
