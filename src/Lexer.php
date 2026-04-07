<?php

namespace JsonataPhp;

class Lexer
{
    /**
     * @return array<int, array{type: string, value: mixed, position: int}>
     */
    public function tokenize(string $expression): array
    {
        $tokens = [];
        $length = strlen($expression);
        $offset = 0;
        $previousToken = null;

        while ($offset < $length) {
            $character = $expression[$offset];

            if (ctype_space($character)) {
                $offset++;

                continue;
            }

            $compoundOperator = $this->readCompoundOperator($expression, $offset);
            if ($compoundOperator !== null) {
                $tokens[] = $compoundOperator;
                $previousToken = $compoundOperator;

                continue;
            }

            if (in_array($character, ['{', '}', '(', ')', '[', ']', ',', ':', '.', '?', ';'], true)) {
                $token = [
                    'type' => $character,
                    'value' => $character,
                    'position' => $offset + 1,
                ];
                $tokens[] = $token;
                $previousToken = $token;
                $offset++;

                continue;
            }

            if ($character === '/' && $this->shouldReadRegex($previousToken)) {
                $token = $this->readRegexToken($expression, $offset);
                $tokens[] = $token;
                $previousToken = $token;

                continue;
            }

            if (in_array($character, ['=', '&', '+', '-', '*', '/', '%', '<', '>', '^', '|', '@', '#'], true)) {
                $token = [
                    'type' => 'operator',
                    'value' => $character,
                    'position' => $offset + 1,
                ];
                $tokens[] = $token;
                $previousToken = $token;
                $offset++;

                continue;
            }

            if ($character === '"' || $character === '\'' || $character === '`') {
                $token = $this->readStringToken($expression, $offset, $character);
                $tokens[] = $token;
                $previousToken = $token;

                continue;
            }

            if (ctype_digit($character)) {
                $token = $this->readNumberToken($expression, $offset);
                $tokens[] = $token;
                $previousToken = $token;

                continue;
            }

            if (substr($expression, $offset, 2) === 'λ') {
                $token = [
                    'type' => 'keyword',
                    'value' => 'function',
                    'position' => $offset + 1,
                ];
                $tokens[] = $token;
                $previousToken = $token;
                $offset += 2;

                continue;
            }

            if ($character === '$' || ctype_alpha($character) || $character === '_') {
                $token = $this->readIdentifierToken($expression, $offset);
                $tokens[] = $token;
                $previousToken = $token;

                continue;
            }

            throw $this->syntaxError(
                sprintf('Unexpected token [%s].', $character),
                $offset + 1
            );
        }

        $tokens[] = [
            'type' => 'eof',
            'value' => null,
            'position' => $length + 1,
        ];

        return $tokens;
    }

    /**
     * @param  array{type: string, value: mixed, position: int}|null  $previousToken
     */
    private function shouldReadRegex(?array $previousToken): bool
    {
        if ($previousToken === null) {
            return true;
        }

        return in_array($previousToken['type'], ['(', '[', '{', ',', ':', ';', '?'], true)
            || ($previousToken['type'] === 'operator' && $previousToken['value'] !== '??' && $previousToken['value'] !== '?:');
    }

    /**
     * @return array{type: string, value: mixed, position: int}|null
     */
    private function readCompoundOperator(string $expression, int &$offset): ?array
    {
        $position = $offset + 1;
        $next = substr($expression, $offset, 2);

        if (! in_array($next, [':=', '!=', '<=', '>=', '**', '..', '~>', '?:', '??'], true)) {
            return null;
        }

        $offset += 2;

        return [
            'type' => 'operator',
            'value' => $next,
            'position' => $position,
        ];
    }

    /**
     * @return array{type: string, value: mixed, position: int}
     */
    private function readRegexToken(string $expression, int &$offset): array
    {
        $position = $offset + 1;
        $offset++;
        $pattern = '';
        $length = strlen($expression);

        while ($offset < $length) {
            $character = $expression[$offset];

            if ($character === '\\') {
                if ($offset + 1 >= $length) {
                    throw $this->syntaxError('Unterminated regular expression literal.', $position);
                }

                $pattern .= $character.$expression[$offset + 1];
                $offset += 2;

                continue;
            }

            if ($character === '/') {
                $offset++;
                break;
            }

            $pattern .= $character;
            $offset++;
        }

        if ($offset > $length) {
            throw $this->syntaxError('Unterminated regular expression literal.', $position);
        }

        $modifiers = '';
        while ($offset < $length && preg_match('/[a-z]/i', $expression[$offset])) {
            $modifiers .= $expression[$offset];
            $offset++;
        }

        return [
            'type' => 'regex',
            'value' => [
                'pattern' => $pattern,
                'modifiers' => $modifiers,
            ],
            'position' => $position,
        ];
    }

    /**
     * @return array{type: string, value: mixed, position: int}
     */
    private function readStringToken(string $expression, int &$offset, string $quote): array
    {
        $position = $offset + 1;
        $offset++;
        $buffer = '';
        $length = strlen($expression);

        while ($offset < $length) {
            $character = $expression[$offset];

            if ($character === '\\') {
                $offset++;
                if ($offset >= $length) {
                    throw $this->syntaxError('Unterminated string literal.', $position);
                }

                $buffer .= stripcslashes('\\'.$expression[$offset]);
                $offset++;

                continue;
            }

            if ($character === $quote) {
                $offset++;

                return [
                    'type' => $quote === '`' ? 'identifier' : 'string',
                    'value' => $buffer,
                    'position' => $position,
                ];
            }

            $buffer .= $character;
            $offset++;
        }

        throw $this->syntaxError('Unterminated string literal.', $position);
    }

    /**
     * @return array{type: string, value: mixed, position: int}
     */
    private function readNumberToken(string $expression, int &$offset): array
    {
        $position = $offset + 1;
        $buffer = '';
        $length = strlen($expression);
        $hasDecimalPoint = false;

        while ($offset < $length) {
            $character = $expression[$offset];

            if (
                $character === '.'
                && ! $hasDecimalPoint
                && isset($expression[$offset + 1])
                && ctype_digit($expression[$offset + 1])
            ) {
                $hasDecimalPoint = true;
                $buffer .= $character;
                $offset++;

                continue;
            }

            if (! ctype_digit($character)) {
                break;
            }

            $buffer .= $character;
            $offset++;
        }

        return [
            'type' => 'number',
            'value' => $hasDecimalPoint ? (float) $buffer : (int) $buffer,
            'position' => $position,
        ];
    }

    /**
     * @return array{type: string, value: mixed, position: int}
     */
    private function readIdentifierToken(string $expression, int &$offset): array
    {
        $position = $offset + 1;
        $buffer = '';
        $length = strlen($expression);

        while ($offset < $length) {
            $character = $expression[$offset];

            if (! preg_match('/[A-Za-z0-9_$]/', $character)) {
                break;
            }

            $buffer .= $character;
            $offset++;
        }

        return match ($buffer) {
            'function' => ['type' => 'keyword', 'value' => 'function', 'position' => $position],
            'or', 'and', 'in' => ['type' => 'operator', 'value' => $buffer, 'position' => $position],
            'true' => ['type' => 'boolean', 'value' => true, 'position' => $position],
            'false' => ['type' => 'boolean', 'value' => false, 'position' => $position],
            'null' => ['type' => 'null', 'value' => null, 'position' => $position],
            default => [
                'type' => str_starts_with($buffer, '$') ? 'variable' : 'identifier',
                'value' => $buffer,
                'position' => $position,
            ],
        };
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
