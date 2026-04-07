<?php

namespace JsonataPhp;

class TokenStream
{
    /**
     * @param  array<int, array{type: string, value: mixed, position: int}>  $tokens
     */
    public function __construct(
        private readonly array $tokens,
        private int $cursor = 0,
    ) {}

    /**
     * @return array{type: string, value: mixed, position: int}
     */
    public function peek(int $offset = 0): array
    {
        return $this->tokens[$this->cursor + $offset] ?? [
            'type' => 'eof',
            'value' => null,
            'position' => 0,
        ];
    }

    /**
     * @return array{type: string, value: mixed, position: int}
     */
    public function advance(): array
    {
        return $this->tokens[$this->cursor++] ?? [
            'type' => 'eof',
            'value' => null,
            'position' => 0,
        ];
    }

    public function check(string $type, mixed $value = null, bool $checkValue = false): bool
    {
        $token = $this->peek();

        if ($token['type'] !== $type) {
            return false;
        }

        if ($checkValue && $token['value'] !== $value) {
            return false;
        }

        return true;
    }

    public function match(string $type, mixed $value = null): bool
    {
        $checkValue = func_num_args() === 2;

        if (! $this->check($type, $value, $checkValue)) {
            return false;
        }

        $this->advance();

        return true;
    }

    /**
     * @return array{type: string, value: mixed, position: int}
     */
    public function expect(string $type, mixed $value = null): array
    {
        $token = $this->peek();
        $checkValue = func_num_args() === 2;

        if ($this->check($type, $value, $checkValue)) {
            $this->advance();

            return $this->tokens[$this->cursor - 1];
        }

        throw $this->syntaxError(
            sprintf('Expected %s, found [%s].', $type, (string) ($token['value'] ?? $token['type'])),
            (int) $token['position']
        );
    }

    /**
     * @param  array<int, string>  $types
     * @return array{type: string, value: mixed, position: int}
     */
    public function expectAny(array $types): array
    {
        $token = $this->peek();

        if (in_array($token['type'], $types, true)) {
            return $this->advance();
        }

        throw $this->syntaxError(
            sprintf('Expected one of [%s], found [%s].', implode(', ', $types), (string) ($token['value'] ?? $token['type'])),
            (int) $token['position']
        );
    }

    private function syntaxError(string $message, int $position): EvaluationException
    {
        return new EvaluationException(
            sprintf('Error S0203: %s', $message),
            'S0203',
            $position,
            ['position' => $position]
        );
    }
}
