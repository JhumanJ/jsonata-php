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

            if (substr($expression, $offset, 2) === '/*') {
                $end = strpos($expression, '*/', $offset + 2);

                if ($end === false) {
                    throw new EvaluationException(
                        'Error S0106: Comment has no closing tag',
                        'S0106',
                        $offset + 1,
                        ['position' => $offset + 1]
                    );
                }

                $offset = $end + 2;

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

            if (in_array($character, ['=', '&', '+', '-', '*', '/', '%', '<', '>', '^', '|', '@', '#', '!'], true)) {
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

            if ($character === '$' || $this->isIdentifierStartCharacter($this->readCharacter($expression, $offset))) {
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

                $buffer .= $this->readEscapedCharacter($expression, $offset, $position);
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

        if ($quote === '`') {
            throw new EvaluationException(
                'Error S0105: Quoted property name must be terminated with a backquote (`)',
                'S0105',
                $length + 1,
                ['position' => $length + 1]
            );
        }

        if ($position > 1 && preg_match('/[A-Za-z0-9_$]/', $expression[$position - 2]) === 1) {
            throw new EvaluationException(
                sprintf('Error S0202: Expected ")", got "%s"', substr($expression, $position - 2)),
                'S0202',
                $position,
                ['position' => $position]
            );
        }

        throw new EvaluationException(
            'Error S0101: String literal must be terminated by a matching quote',
            'S0101',
            $length + 1,
            ['position' => $length + 1]
        );
    }

    private function readEscapedCharacter(string $expression, int &$offset, int $stringPosition): string
    {
        $character = $expression[$offset];

        return match ($character) {
            '"', "'", '`', '\\', '/' => $character,
            'b' => "\b",
            'f' => "\f",
            'n' => "\n",
            'r' => "\r",
            't' => "\t",
            'u' => $this->readUnicodeEscape($expression, $offset, $stringPosition),
            default => throw new EvaluationException(
                sprintf('Error S0103: Unsupported escape sequence: \\\\%s', $character),
                'S0103',
                $offset + 1,
                ['position' => $offset + 1]
            ),
        };
    }

    private function readUnicodeEscape(string $expression, int &$offset, int $stringPosition): string
    {
        $digits = substr($expression, $offset + 1, 4);

        if (strlen($digits) !== 4 || preg_match('/^[0-9a-fA-F]{4}$/', $digits) !== 1) {
            throw new EvaluationException(
                'Error S0104: The escape sequence \u must be followed by 4 hex digits',
                'S0104',
                $stringPosition + 1,
                ['position' => $stringPosition + 1]
            );
        }

        $offset += 4;
        $codepoint = hexdec($digits);

        if ($codepoint >= 0xD800 && $codepoint <= 0xDBFF) {
            $nextEscape = substr($expression, $offset + 1, 6);
            if (preg_match('/^\\\\u([0-9a-fA-F]{4})$/', $nextEscape, $match) === 1) {
                $low = hexdec($match[1]);
                if ($low >= 0xDC00 && $low <= 0xDFFF) {
                    $offset += 6;
                    $codepoint = 0x10000 + (($codepoint - 0xD800) * 0x400) + ($low - 0xDC00);
                }
            }
        }

        return mb_chr($codepoint, 'UTF-8');
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

        if (
            isset($expression[$offset])
            && ($expression[$offset] === 'e' || $expression[$offset] === 'E')
        ) {
            $exponentOffset = $offset;
            $exponent = $expression[$offset];
            $offset++;

            if (isset($expression[$offset]) && ($expression[$offset] === '+' || $expression[$offset] === '-')) {
                $exponent .= $expression[$offset];
                $offset++;
            }

            $digits = '';
            while (isset($expression[$offset]) && ctype_digit($expression[$offset])) {
                $digits .= $expression[$offset];
                $offset++;
            }

            if ($digits === '') {
                $offset = $exponentOffset;
            } else {
                $buffer .= $exponent.$digits;
                $hasDecimalPoint = true;
            }
        }

        $value = $hasDecimalPoint ? (float) $buffer : (int) $buffer;

        if (is_float($value) && (is_infinite($value) || is_nan($value))) {
            throw new EvaluationException(
                sprintf('Error S0102: Number out of range: "%s"', $buffer),
                'S0102',
                $position,
                ['position' => $position]
            );
        }

        return [
            'type' => 'number',
            'value' => $value,
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
            $character = $this->readCharacter($expression, $offset);

            if (! $this->isIdentifierPartCharacter($character)) {
                break;
            }

            $buffer .= $character;
            $offset += strlen($character);
        }

        return match ($buffer) {
            'function' => ['type' => 'keyword', 'value' => 'function', 'position' => $position],
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

    private function readCharacter(string $expression, int $offset): string
    {
        $character = $expression[$offset];

        if (ord($character) < 0x80) {
            return $character;
        }

        preg_match('/./us', substr($expression, $offset), $match);

        return $match[0] ?? $character;
    }

    private function isIdentifierStartCharacter(string $character): bool
    {
        return preg_match('/^(?:[$_]|\p{L})$/u', $character) === 1;
    }

    private function isIdentifierPartCharacter(string $character): bool
    {
        return preg_match('/^(?:[$_]|\p{L}|\p{N}|\p{Mn}|\p{Mc})$/u', $character) === 1;
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
