<?php

namespace JsonataPhp;

class RegexPattern
{
    public function __construct(
        public readonly string $pattern,
        public readonly string $flags = '',
    ) {}

    public function toPcre(): string
    {
        $delimiter = '~';
        $escaped = $this->escapeDelimiter($this->pattern, $delimiter);

        return $delimiter.$escaped.$delimiter.$this->flags;
    }

    private function escapeDelimiter(string $pattern, string $delimiter): string
    {
        $escaped = '';
        $length = strlen($pattern);

        for ($index = 0; $index < $length; $index++) {
            $character = $pattern[$index];

            if ($character === $delimiter && ! $this->isEscaped($pattern, $index)) {
                $escaped .= '\\';
            }

            $escaped .= $character;
        }

        return $escaped;
    }

    private function isEscaped(string $pattern, int $index): bool
    {
        $backslashes = 0;

        for ($cursor = $index - 1; $cursor >= 0 && $pattern[$cursor] === '\\'; $cursor--) {
            $backslashes++;
        }

        return $backslashes % 2 === 1;
    }
}
