<?php

namespace JsonataPhp\Formatters;

use JsonataPhp\EvaluationException;

class IntegerFormatter
{
    /**
     * @var array<int, string>
     */
    private const ROMAN_NUMERALS = [
        1000 => 'M',
        900 => 'CM',
        500 => 'D',
        400 => 'CD',
        100 => 'C',
        90 => 'XC',
        50 => 'L',
        40 => 'XL',
        10 => 'X',
        9 => 'IX',
        5 => 'V',
        4 => 'IV',
        1 => 'I',
    ];

    /**
     * @var array<int, string>
     */
    private const SMALL_WORDS = [
        0 => 'zero',
        1 => 'one',
        2 => 'two',
        3 => 'three',
        4 => 'four',
        5 => 'five',
        6 => 'six',
        7 => 'seven',
        8 => 'eight',
        9 => 'nine',
        10 => 'ten',
        11 => 'eleven',
        12 => 'twelve',
        13 => 'thirteen',
        14 => 'fourteen',
        15 => 'fifteen',
        16 => 'sixteen',
        17 => 'seventeen',
        18 => 'eighteen',
        19 => 'nineteen',
    ];

    /**
     * @var array<int, string>
     */
    private const TENS_WORDS = [
        20 => 'twenty',
        30 => 'thirty',
        40 => 'forty',
        50 => 'fifty',
        60 => 'sixty',
        70 => 'seventy',
        80 => 'eighty',
        90 => 'ninety',
    ];

    /**
     * @var array<int, string>
     */
    private const SCALE_WORDS = [
        1000000000 => 'billion',
        1000000 => 'million',
        1000 => 'thousand',
        100 => 'hundred',
    ];

    /**
     * @var array<string, int>
     */
    private array $wordValues;

    public function __construct()
    {
        $this->wordValues = $this->buildWordValues();
    }

    public function format(int $value, string $picture): string
    {
        return match (true) {
            $picture === 'A' => $this->toLetters($value, 'A'),
            $picture === 'a' => $this->toLetters($value, 'a'),
            $picture === 'I' => $this->toRoman($value),
            $picture === 'i' => strtolower($this->toRoman($value)),
            in_array($picture, ['W', 'Ww', 'w'], true) => $this->toWords($value, $picture),
            default => $this->formatDecimal($value, $picture),
        };
    }

    public function parse(string $value, string $picture): int
    {
        return match (true) {
            $picture === 'A' => $this->lettersToDecimal($value, 'A'),
            $picture === 'a' => $this->lettersToDecimal($value, 'a'),
            $picture === 'I', $picture === 'i' => $this->romanToDecimal(strtoupper($value)),
            in_array($picture, ['W', 'Ww', 'w'], true) => $this->wordsToDecimal($value),
            default => $this->parseDecimal($value),
        };
    }

    private function formatDecimal(int $value, string $picture): string
    {
        if ($picture === '') {
            return (string) $value;
        }

        $width = substr_count($picture, '0');
        $negative = $value < 0;
        $absolute = (string) abs($value);
        $formatted = str_pad($absolute, $width, '0', STR_PAD_LEFT);

        if (! str_contains($picture, ',')) {
            return $negative ? '-'.$formatted : $formatted;
        }

        $separatorPos = strrpos($picture, ',');
        $grouping = $separatorPos === false ? 3 : max(1, strlen($picture) - $separatorPos - 1);
        $grouped = '';

        while (strlen($formatted) > $grouping) {
            $grouped = ','.substr($formatted, -$grouping).$grouped;
            $formatted = substr($formatted, 0, -$grouping);
        }

        $grouped = $formatted.$grouped;

        return $negative ? '-'.$grouped : $grouped;
    }

    private function parseDecimal(string $value): int
    {
        $normalized = str_replace(',', '', trim($value));

        if (! preg_match('/^-?\d+$/', $normalized)) {
            throw new EvaluationException(
                'Error D3131: The integer string could not be parsed.',
                'D3131'
            );
        }

        return (int) $normalized;
    }

    private function toLetters(int $value, string $baseChar): string
    {
        if ($value <= 0) {
            throw new EvaluationException(
                'Error D3132: Letter formats require a positive integer.',
                'D3132'
            );
        }

        $letters = [];
        $code = ord($baseChar);

        while ($value > 0) {
            $value--;
            array_unshift($letters, chr($code + ($value % 26)));
            $value = intdiv($value, 26);
        }

        return implode('', $letters);
    }

    private function lettersToDecimal(string $letters, string $baseChar): int
    {
        $letters = trim($letters);
        if ($letters === '') {
            throw new EvaluationException(
                'Error D3131: The integer string could not be parsed.',
                'D3131'
            );
        }

        $code = ord($baseChar);
        $value = 0;

        for ($index = 0; $index < strlen($letters); $index++) {
            $digit = ord($letters[$index]) - $code + 1;
            if ($digit < 1 || $digit > 26) {
                throw new EvaluationException(
                    'Error D3131: The integer string could not be parsed.',
                    'D3131'
                );
            }

            $value = ($value * 26) + $digit;
        }

        return $value;
    }

    private function toRoman(int $value): string
    {
        if ($value <= 0) {
            throw new EvaluationException(
                'Error D3132: Roman numeral formats require a positive integer.',
                'D3132'
            );
        }

        $result = '';
        foreach (self::ROMAN_NUMERALS as $decimal => $roman) {
            while ($value >= $decimal) {
                $result .= $roman;
                $value -= $decimal;
            }
        }

        return $result;
    }

    private function romanToDecimal(string $roman): int
    {
        $values = ['M' => 1000, 'D' => 500, 'C' => 100, 'L' => 50, 'X' => 10, 'V' => 5, 'I' => 1];
        $total = 0;
        $max = 0;

        for ($index = strlen($roman) - 1; $index >= 0; $index--) {
            $digit = $roman[$index];
            $value = $values[$digit] ?? null;

            if ($value === null) {
                throw new EvaluationException(
                    'Error D3131: The integer string could not be parsed.',
                    'D3131'
                );
            }

            if ($value < $max) {
                $total -= $value;
            } else {
                $total += $value;
                $max = $value;
            }
        }

        return $total;
    }

    private function toWords(int $value, string $picture): string
    {
        $words = $this->formatWords(abs($value));
        if ($value < 0) {
            $words = 'minus '.$words;
        }

        return match ($picture) {
            'W' => strtoupper($words),
            'Ww' => ucwords($words),
            default => $words,
        };
    }

    private function formatWords(int $value): string
    {
        if ($value < 20) {
            return self::SMALL_WORDS[$value];
        }

        if ($value < 100) {
            $tens = intdiv($value, 10) * 10;
            $remainder = $value % 10;

            return $remainder === 0
                ? self::TENS_WORDS[$tens]
                : self::TENS_WORDS[$tens].'-'.$this->formatWords($remainder);
        }

        if ($value < 1000) {
            $hundreds = intdiv($value, 100);
            $remainder = $value % 100;
            $result = self::SMALL_WORDS[$hundreds].' hundred';

            return $remainder === 0 ? $result : $result.' and '.$this->formatWords($remainder);
        }

        foreach ([1000000000, 1000000, 1000] as $scale) {
            if ($value >= $scale) {
                $leading = intdiv($value, $scale);
                $remainder = $value % $scale;
                $result = $this->formatWords($leading).' '.self::SCALE_WORDS[$scale];

                if ($remainder === 0) {
                    return $result;
                }

                return $result.($remainder < 100 ? ' and ' : ' ').$this->formatWords($remainder);
            }
        }

        return '';
    }

    private function wordsToDecimal(string $value): int
    {
        $tokens = preg_split('/[\s,-]+/', strtolower(trim($value))) ?: [];
        if ($tokens === []) {
            throw new EvaluationException(
                'Error D3131: The integer string could not be parsed.',
                'D3131'
            );
        }

        $current = 0;
        $result = 0;

        foreach ($tokens as $token) {
            if ($token === '' || $token === 'and') {
                continue;
            }

            if (isset($this->wordValues[$token])) {
                $current += $this->wordValues[$token];

                continue;
            }

            if ($token === 'hundred') {
                $current *= 100;

                continue;
            }

            if ($token === 'thousand') {
                $result += $current * 1000;
                $current = 0;

                continue;
            }

            if ($token === 'million') {
                $result += $current * 1000000;
                $current = 0;

                continue;
            }

            if ($token === 'billion') {
                $result += $current * 1000000000;
                $current = 0;

                continue;
            }

            throw new EvaluationException(
                'Error D3131: The integer string could not be parsed.',
                'D3131'
            );
        }

        return $result + $current;
    }

    /**
     * @return array<string, int>
     */
    private function buildWordValues(): array
    {
        $values = [];

        foreach (self::SMALL_WORDS as $number => $word) {
            if ($number === 0) {
                continue;
            }

            $values[$word] = $number;
        }

        foreach (self::TENS_WORDS as $number => $word) {
            $values[$word] = $number;
        }

        return $values;
    }
}
