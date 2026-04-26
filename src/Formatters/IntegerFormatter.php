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
     * @var array<string, string>
     */
    private const ORDINAL_EXCEPTIONS = [
        'one' => 'first',
        'two' => 'second',
        'three' => 'third',
        'five' => 'fifth',
        'eight' => 'eighth',
        'nine' => 'ninth',
        'twelve' => 'twelfth',
        'twenty' => 'twentieth',
        'thirty' => 'thirtieth',
        'forty' => 'fortieth',
        'fifty' => 'fiftieth',
        'sixty' => 'sixtieth',
        'seventy' => 'seventieth',
        'eighty' => 'eightieth',
        'ninety' => 'ninetieth',
        'hundred' => 'hundredth',
        'thousand' => 'thousandth',
        'million' => 'millionth',
        'billion' => 'billionth',
        'trillion' => 'trillionth',
    ];

    /**
     * @var array<string, string>
     */
    private const ORDINAL_WORDS = [
        'first' => 'one',
        'second' => 'two',
        'third' => 'three',
        'fifth' => 'five',
        'eighth' => 'eight',
        'ninth' => 'nine',
        'twelfth' => 'twelve',
        'twentieth' => 'twenty',
        'thirtieth' => 'thirty',
        'fortieth' => 'forty',
        'fiftieth' => 'fifty',
        'sixtieth' => 'sixty',
        'seventieth' => 'seventy',
        'eightieth' => 'eighty',
        'ninetieth' => 'ninety',
        'hundredth' => 'hundred',
        'thousandth' => 'thousand',
        'millionth' => 'million',
        'billionth' => 'billion',
        'trillionth' => 'trillion',
    ];

    /**
     * @var array<string, int>
     */
    private array $wordValues;

    public function __construct()
    {
        $this->wordValues = $this->buildWordValues();
    }

    public function format(int|float $value, string $picture): string
    {
        [$primary, $modifier] = $this->splitPicture($picture);
        $ordinal = $modifier === 'o';

        return match (true) {
            $primary === 'A' => $this->toLetters((int) $value, 'A'),
            $primary === 'a' => $this->toLetters((int) $value, 'a'),
            $primary === 'I' => $this->formatRoman((int) $value, false),
            $primary === 'i' => $this->formatRoman((int) $value, true),
            in_array($primary, ['W', 'Ww', 'w'], true) => $this->toWords($value, $primary, $ordinal),
            $this->containsNonAsciiLetter($primary) => throw new EvaluationException(
                'Error D3130: Unsupported integer format token.',
                'D3130'
            ),
            default => $this->formatDecimal((int) $value, $primary, $ordinal),
        };
    }

    public function parse(string $value, string $picture): int|float
    {
        [$primary, $modifier] = $this->splitPicture($picture);
        $value = trim($value);

        return match (true) {
            $primary === 'A' => $this->lettersToDecimal($value, 'A'),
            $primary === 'a' => $this->lettersToDecimal($value, 'a'),
            $primary === 'I', $primary === 'i' => $value === '' ? 0 : $this->romanToDecimal(strtoupper($value)),
            in_array($primary, ['W', 'Ww', 'w'], true) => $this->wordsToDecimal($modifier === 'o' ? $this->normalizeOrdinalWords($value) : $value),
            $this->containsNonAsciiLetter($primary) || ! $this->hasMandatoryDigit($primary) => throw new EvaluationException(
                'Error D3130: Unsupported integer format token.',
                'D3130'
            ),
            default => $this->parseDecimal($value, $primary, $modifier === 'o'),
        };
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    private function splitPicture(string $picture): array
    {
        [$primary, $modifier] = array_pad(explode(';', $picture, 2), 2, null);

        return [$primary, $modifier];
    }

    private function formatDecimal(int $value, string $picture, bool $ordinal): string
    {
        if ($picture === '') {
            return (string) $value;
        }

        $zeroDigit = $this->zeroDigitForPicture($picture);
        $this->assertSingleDigitFamily($picture, $zeroDigit);
        $normalizedPicture = $zeroDigit === '0' ? $picture : $this->normalizeDigits($picture, $zeroDigit);
        $width = substr_count($normalizedPicture, '0');
        $negative = $value < 0;
        $formatted = str_pad((string) abs($value), $width, '0', STR_PAD_LEFT);
        $formatted = $this->applyPictureGrouping($formatted, $normalizedPicture);

        if ($zeroDigit !== '0') {
            $formatted = $this->localizeDigits($formatted, $zeroDigit);
        }

        if ($ordinal) {
            $formatted .= $this->ordinalSuffix($value);
        }

        return $negative ? '-'.$formatted : $formatted;
    }

    private function parseDecimal(string $value, string $picture, bool $ordinal): int
    {
        $zeroDigit = $this->zeroDigitForPicture($picture);
        $this->assertSingleDigitFamily($picture, $zeroDigit);
        $normalized = $zeroDigit === '0' ? $value : $this->normalizeDigits($value, $zeroDigit);

        if ($ordinal) {
            $normalized = preg_replace('/(st|nd|rd|th)$/i', '', $normalized) ?? $normalized;
        }

        $digits = preg_replace('/[^\d-]/', '', $normalized) ?? '';

        if ($digits === '' || preg_match('/^-?\d+$/', $digits) !== 1) {
            throw new EvaluationException(
                'Error D3131: The integer string could not be parsed.',
                'D3131'
            );
        }

        return (int) $digits;
    }

    private function formatRoman(int $value, bool $lowercase): string
    {
        if ($value === 0) {
            return '';
        }

        $roman = $this->toRoman(abs($value));
        $roman = $lowercase ? strtolower($roman) : $roman;

        return $value < 0 ? '-'.$roman : $roman;
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

    private function toWords(int|float $value, string $picture, bool $ordinal): string
    {
        $words = $this->formatWords(abs($value));
        if ($ordinal) {
            $words = $this->ordinalizeWords($words);
        }

        if ($value < 0) {
            $words = 'minus '.$words;
        }

        return match ($picture) {
            'W' => strtoupper($words),
            'Ww' => $this->titleCaseWords($words),
            default => $words,
        };
    }

    private function formatWords(int|float $value): string
    {
        $value = floor($value);

        if ($value < 20) {
            return self::SMALL_WORDS[(int) $value];
        }

        if ($value < 100) {
            $tens = (int) (floor($value / 10) * 10);
            $remainder = (int) fmod($value, 10);

            return $remainder === 0
                ? self::TENS_WORDS[$tens]
                : self::TENS_WORDS[$tens].'-'.$this->formatWords($remainder);
        }

        if ($value < 1000) {
            $hundreds = (int) floor($value / 100);
            $remainder = fmod($value, 100);
            $result = self::SMALL_WORDS[$hundreds].' hundred';

            return $remainder == 0 ? $result : $result.' and '.$this->formatWords($remainder);
        }

        foreach ([1000000000000 => 'trillion', 1000000000 => 'billion', 1000000 => 'million', 1000 => 'thousand'] as $scale => $word) {
            if ($value >= $scale) {
                $leading = floor($value / $scale);
                $remainder = fmod($value, $scale);
                $result = $this->formatWords($leading).' '.$word;

                if ($value > 1.0e18 && abs($remainder / $value) < 1.0e-12) {
                    $remainder = 0;
                }

                if ($remainder == 0) {
                    return $result;
                }

                return $result.($remainder < 100 ? ' and ' : ', ').$this->formatWords($remainder);
            }
        }

        return '';
    }

    private function wordsToDecimal(string $value): int|float
    {
        $tokens = preg_split('/[\s,-]+/', strtolower(trim($value))) ?: [];
        if ($tokens === []) {
            throw new EvaluationException(
                'Error D3131: The integer string could not be parsed.',
                'D3131'
            );
        }

        $current = 0;
        $total = 0;
        $largeScales = [
            'thousand' => 1000,
            'million' => 1000000,
            'billion' => 1000000000,
            'trillion' => 1000000000000,
        ];

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

            if (isset($largeScales[$token])) {
                $scale = $largeScales[$token];
                if ($current === 0 && $total > 0) {
                    $total *= $scale;
                } else {
                    $total += ($current === 0 ? 1 : $current) * $scale;
                }

                $current = 0;

                continue;
            }

            throw new EvaluationException(
                'Error D3131: The integer string could not be parsed.',
                'D3131'
            );
        }

        $result = $total + $current;

        return $result > PHP_INT_MAX ? (float) $result : (int) $result;
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

    private function ordinalSuffix(int $value): string
    {
        $value = abs($value);
        $mod100 = $value % 100;

        if ($mod100 >= 11 && $mod100 <= 13) {
            return 'th';
        }

        return match ($value % 10) {
            1 => 'st',
            2 => 'nd',
            3 => 'rd',
            default => 'th',
        };
    }

    private function ordinalizeWords(string $words): string
    {
        return preg_replace_callback('/([a-z]+)$/', function (array $matches): string {
            $word = $matches[1];

            return self::ORDINAL_EXCEPTIONS[$word] ?? $word.'th';
        }, $words) ?? $words;
    }

    private function normalizeOrdinalWords(string $value): string
    {
        return preg_replace_callback('/([a-z]+)$/i', function (array $matches): string {
            $lower = strtolower($matches[1]);
            $cardinal = self::ORDINAL_WORDS[$lower] ?? null;

            if ($cardinal === null && str_ends_with($lower, 'th')) {
                $cardinal = substr($lower, 0, -2);
            }

            if ($cardinal === null) {
                return $matches[1];
            }

            return ctype_upper($matches[1]) ? strtoupper($cardinal) : $cardinal;
        }, $value) ?? $value;
    }

    private function titleCaseWords(string $words): string
    {
        $title = preg_replace_callback('/\b[a-z]/', fn (array $match): string => strtoupper($match[0]), $words) ?? $words;

        return str_replace(' And ', ' and ', $title);
    }

    private function applyPictureGrouping(string $digits, string $picture): string
    {
        $separators = [];
        $chars = $this->chars($picture);
        $digitPositions = [];

        foreach ($chars as $index => $char) {
            if ($char === '#' || $char === '0') {
                $digitPositions[] = $index;
            }
        }

        if ($digitPositions === []) {
            return $digits;
        }

        $lastDigit = max($digitPositions);
        foreach ($chars as $index => $char) {
            if ($index < $lastDigit && $char !== '#' && $char !== '0') {
                $separators[] = [$char, $this->countDigitsAfter($chars, $index)];
            }
        }

        return $this->applyGroupingSpec($digits, $separators);
    }

    /**
     * @param  list<array{0: string, 1: int}>  $separators
     */
    private function applyGroupingSpec(string $digits, array $separators): string
    {
        if ($separators === []) {
            return $digits;
        }

        $spec = array_reverse($separators);
        $sizes = [];
        $previous = 0;
        foreach ($spec as [, $distance]) {
            $sizes[] = $distance - $previous;
            $previous = $distance;
        }

        $repeat = count($spec) === 1 || count(array_unique($sizes)) === 1 && count(array_unique(array_column($spec, 0))) === 1;
        $result = '';
        $remaining = $digits;
        $previousDistance = 0;

        foreach ($spec as [$separator, $distance]) {
            $size = $distance - $previousDistance;
            if (strlen($remaining) <= $size) {
                return $remaining.$result;
            }

            $result = $separator.substr($remaining, -$size).$result;
            $remaining = substr($remaining, 0, -$size);
            $previousDistance = $distance;
        }

        if (! $repeat) {
            return $remaining.$result;
        }

        $size = $spec[array_key_last($spec)][1] - (count($spec) > 1 ? $spec[array_key_last($spec) - 1][1] : 0);
        $separator = $spec[array_key_last($spec)][0];

        while (strlen($remaining) > $size) {
            $result = $separator.substr($remaining, -$size).$result;
            $remaining = substr($remaining, 0, -$size);
        }

        return $remaining.$result;
    }

    /**
     * @param  list<string>  $chars
     */
    private function countDigitsAfter(array $chars, int $position): int
    {
        $count = 0;

        for ($index = $position + 1; $index < count($chars); $index++) {
            if ($chars[$index] === '#' || $chars[$index] === '0') {
                $count++;
            }
        }

        return $count;
    }

    private function zeroDigitForPicture(string $picture): string
    {
        foreach ($this->chars($picture) as $char) {
            $normalized = $this->normalizeDigit($char);
            if ($normalized !== null && $char !== '#' && $char !== $normalized) {
                $digit = (int) $normalized;

                return $this->shiftCodepoint($char, -$digit);
            }
        }

        return '0';
    }

    private function assertSingleDigitFamily(string $picture, string $zeroDigit): void
    {
        foreach ($this->chars($picture) as $char) {
            $normalized = $this->normalizeDigit($char);
            if ($normalized !== null && $char !== '#' && $this->digitValue($char, $zeroDigit) === null) {
                throw new EvaluationException(
                    'Error D3131: In a decimal digit pattern, all digits must be from the same decimal group.',
                    'D3131'
                );
            }
        }
    }

    private function normalizeDigits(string $value, string $zeroDigit): string
    {
        $result = '';

        foreach ($this->chars($value) as $char) {
            $digit = $this->digitValue($char, $zeroDigit);
            $result .= $digit === null ? $char : (string) $digit;
        }

        return $result;
    }

    private function localizeDigits(string $value, string $zeroDigit): string
    {
        $result = '';

        foreach (str_split($value) as $char) {
            $result .= ctype_digit($char) ? $this->shiftCodepoint($zeroDigit, (int) $char) : $char;
        }

        return $result;
    }

    private function normalizeDigit(string $char): ?string
    {
        $codepoint = $this->codepoint($char);

        if ($codepoint >= 0x30 && $codepoint <= 0x39) {
            return (string) ($codepoint - 0x30);
        }

        if ($codepoint >= 0x660 && $codepoint <= 0x669) {
            return (string) ($codepoint - 0x660);
        }

        if ($codepoint >= 0xFF10 && $codepoint <= 0xFF19) {
            return (string) ($codepoint - 0xFF10);
        }

        return null;
    }

    private function digitValue(string $char, string $zeroDigit): ?int
    {
        $delta = $this->codepoint($char) - $this->codepoint($zeroDigit);

        return $delta >= 0 && $delta <= 9 ? $delta : null;
    }

    private function shiftCodepoint(string $char, int $delta): string
    {
        return mb_chr($this->codepoint($char) + $delta, 'UTF-8');
    }

    private function codepoint(string $char): int
    {
        return mb_ord($char, 'UTF-8');
    }

    /**
     * @return list<string>
     */
    private function chars(string $value): array
    {
        return preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    private function containsNonAsciiLetter(string $value): bool
    {
        return preg_match('/[^\W\d_]/u', $value) === 1 && preg_match('/[A-Za-z]/', $value) !== 1;
    }

    private function hasMandatoryDigit(string $picture): bool
    {
        foreach ($this->chars($picture) as $char) {
            if ($char !== '#' && $this->normalizeDigit($char) !== null) {
                return true;
            }
        }

        return false;
    }
}
