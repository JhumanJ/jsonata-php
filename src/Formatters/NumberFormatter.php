<?php

namespace JsonataPhp\Formatters;

use JsonataPhp\EvaluationException;

class NumberFormatter
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function format(int|float $value, string $picture, array $options = []): string
    {
        $zeroDigit = (string) ($options['zero-digit'] ?? '0');
        $perMille = (string) ($options['per-mille'] ?? '‰');
        $subPictures = explode(';', $picture);

        if (count($subPictures) > 2) {
            throw $this->error('D3080');
        }

        $negative = $value < 0;
        $subPicture = $negative && isset($subPictures[1]) ? $subPictures[1] : $subPictures[0];
        $analysis = $this->analyze($subPicture, $zeroDigit, $perMille);
        $scaled = abs($value) * $analysis['scale'];
        $body = $analysis['scientific']
            ? $this->formatScientific($scaled, $analysis, $zeroDigit)
            : $this->formatDecimal($scaled, $analysis, $zeroDigit);

        if ($negative && ! isset($subPictures[1])) {
            $body = '-'.$body;
        }

        return $analysis['prefix'].$body.$analysis['suffix'];
    }

    /**
     * @return array<string, mixed>
     */
    private function analyze(string $picture, string $zeroDigit, string $perMille): array
    {
        if (substr_count($picture, '.') > 1) {
            throw $this->error('D3081');
        }

        if (substr_count($picture, '%') > 1) {
            throw $this->error('D3082');
        }

        if (substr_count($picture, $perMille) > 1) {
            throw $this->error('D3083');
        }

        if (str_contains($picture, '%') && str_contains($picture, $perMille)) {
            throw $this->error('D3084');
        }

        $scale = str_contains($picture, '%') ? 100 : (str_contains($picture, $perMille) ? 1000 : 1);
        $scientific = str_contains($picture, 'e');
        [$mantissaPicture, $exponentPicture] = $scientific ? explode('e', $picture, 2) : [$picture, ''];

        if (! $scientific) {
            $this->validateDecimal($mantissaPicture);
        }

        if ($scientific) {
            $this->validateScientific(
                $this->normalizePictureDigits($mantissaPicture, $zeroDigit),
                $this->normalizePictureDigits($exponentPicture, $zeroDigit)
            );
        }

        [$mantissaStart, $mantissaEnd] = $this->activeSpan($mantissaPicture, $zeroDigit);
        if ($mantissaStart === null || $mantissaEnd === null) {
            return [
                'prefix' => $picture,
                'suffix' => '',
                'scale' => $scale,
                'scientific' => false,
                'integer' => '',
                'fraction' => '',
            ];
        }

        $prefix = substr($mantissaPicture, 0, $mantissaStart);
        $suffix = substr($mantissaPicture, $mantissaEnd + 1);
        $numericPicture = substr($mantissaPicture, $mantissaStart, $mantissaEnd - $mantissaStart + 1);
        $prefix = str_replace(['%', $perMille], '', $prefix);
        $suffix = str_replace(['%', $perMille], '', $suffix);
        $suffix .= str_contains($picture, '%') ? '%' : (str_contains($picture, $perMille) ? $perMille : '');

        [$integerPicture, $fractionPicture] = array_pad(explode('.', $numericPicture, 2), 2, '');
        $normalizedInteger = $this->normalizePictureDigits($integerPicture, $zeroDigit);
        $normalizedFraction = $this->normalizePictureDigits($fractionPicture, $zeroDigit);

        $maxFractionDigits = $this->digitCount($normalizedFraction);
        $minFractionDigits = $this->mandatoryDigitCount($normalizedFraction);

        if ($scientific && str_contains($normalizedFraction, '.') === false && str_contains($numericPicture, '.') && $maxFractionDigits === 0) {
            $maxFractionDigits = 1;
        }

        return [
            'prefix' => $prefix,
            'suffix' => $suffix,
            'scale' => $scale,
            'scientific' => $scientific,
            'integer' => $normalizedInteger,
            'fraction' => $normalizedFraction,
            'exponent' => $this->normalizePictureDigits($exponentPicture, $zeroDigit),
            'integerGrouping' => $this->groupingSpec($normalizedInteger, true),
            'fractionGrouping' => $this->groupingSpec($normalizedFraction, false),
            'minIntegerDigits' => $this->mandatoryDigitCount($normalizedInteger),
            'maxFractionDigits' => $maxFractionDigits,
            'minFractionDigits' => $minFractionDigits,
        ];
    }

    /**
     * @return array{0: int|null, 1: int|null}
     */
    private function activeSpan(string $picture, string $zeroDigit): array
    {
        $start = null;
        $end = null;
        $offset = 0;

        foreach ($this->chars($picture) as $char) {
            $length = strlen($char);
            if ($this->isActive($char, $zeroDigit)) {
                $start ??= $offset;
                $end = $offset + $length - 1;
            }

            $offset += $length;
        }

        return [$start, $end];
    }

    /**
     * @param  array<string, mixed>  $analysis
     */
    private function formatDecimal(float|int $value, array $analysis, string $zeroDigit): string
    {
        $rounded = round($value, $analysis['maxFractionDigits'], PHP_ROUND_HALF_EVEN);
        $formatted = number_format($rounded, $analysis['maxFractionDigits'], '.', '');
        [$integer, $fraction] = array_pad(explode('.', $formatted, 2), 2, '');
        $integer = str_pad($integer, max(1, $analysis['minIntegerDigits']), '0', STR_PAD_LEFT);

        if ($analysis['integerGrouping'] !== []) {
            $integer = $this->applyIntegerGrouping($integer, $analysis['integerGrouping']);
        }

        if ($analysis['maxFractionDigits'] > 0) {
            $fraction = rtrim($fraction, '0');
            if (strlen($fraction) < $analysis['minFractionDigits']) {
                $fraction = str_pad($fraction, $analysis['minFractionDigits'], '0');
            }

            if ($fraction !== '' && $analysis['fractionGrouping'] !== []) {
                $fraction = $this->applyFractionGrouping($fraction, $analysis['fractionGrouping']);
            }
        } else {
            $fraction = '';
        }

        $result = $integer.($fraction === '' ? '' : '.'.$fraction);

        return $this->localizeDigits($result, $zeroDigit);
    }

    /**
     * @param  array<string, mixed>  $analysis
     */
    private function formatScientific(float|int $value, array $analysis, string $zeroDigit): string
    {
        $integerDigits = $this->digitCount($analysis['integer']);
        $mandatoryIntegerDigits = $analysis['minIntegerDigits'];
        $fractionDigits = $analysis['maxFractionDigits'];
        $exponent = 0;

        if ($value != 0 && $mandatoryIntegerDigits > 0) {
            $exponent = (int) floor(log10($value)) - $mandatoryIntegerDigits + 1;
        }

        $mantissa = $exponent === 0 ? $value : $value / (10 ** $exponent);
        $mantissa = round($mantissa, $fractionDigits, PHP_ROUND_HALF_EVEN);

        if ($mandatoryIntegerDigits > 0 && $mantissa >= 10 ** $mandatoryIntegerDigits) {
            $mantissa /= 10;
            $exponent++;
        }

        $number = number_format($mantissa, $fractionDigits, '.', '');
        [$integer, $fraction] = array_pad(explode('.', $number, 2), 2, '');

        if ($integerDigits === 0) {
            $integer = '';
        } else {
            $integer = str_pad($integer, $mandatoryIntegerDigits, '0', STR_PAD_LEFT);
        }

        if ($fractionDigits > 0) {
            $fraction = rtrim($fraction, '0');
            if (strlen($fraction) < $analysis['minFractionDigits']) {
                $fraction = str_pad($fraction, $analysis['minFractionDigits'], '0');
            }
        } else {
            $fraction = '';
        }

        $mantissaResult = $integer.($fraction === '' ? '' : '.'.$fraction);
        $exponentDigits = max(1, $this->mandatoryDigitCount($analysis['exponent']));
        $exponentSign = $exponent < 0 ? '-' : '';
        $exponentResult = $exponentSign.str_pad((string) abs($exponent), $exponentDigits, '0', STR_PAD_LEFT);

        return $this->localizeDigits($mantissaResult.'e'.$exponentResult, $zeroDigit);
    }

    private function validateScientific(string $mantissa, string $exponent): void
    {
        [$integer, $fraction] = array_pad(explode('.', $mantissa, 2), 2, '');

        if ($this->digitCount($integer) === 0 && $this->digitCount($fraction) === 0) {
            throw $this->error('D3085');
        }

        if (str_ends_with($integer, ',')) {
            throw $this->error('D3087');
        }

        if (preg_match('/[^\#0-9.]/', $mantissa) === 1) {
            throw $this->error('D3086');
        }

        if (preg_match('/0#/', $integer) === 1) {
            throw $this->error('D3090');
        }

        if (preg_match('/#0/', $fraction) === 1) {
            throw $this->error('D3091');
        }

        if (str_contains($exponent, '%')) {
            throw $this->error('D3092');
        }

        if (preg_match('/[^\#0-9]/', $exponent) === 1) {
            throw $this->error('D3093');
        }
    }

    private function validateDecimal(string $picture): void
    {
        [$integer] = array_pad(explode('.', $picture, 2), 2, '');

        if (str_contains($integer, ',,')) {
            throw $this->error('D3089');
        }

        if (str_ends_with($integer, ',')) {
            throw $this->error('D3088');
        }
    }

    private function error(string $code): EvaluationException
    {
        return new EvaluationException("Error {$code}: Invalid decimal format picture.", $code);
    }

    private function normalizePictureDigits(string $picture, string $zeroDigit): string
    {
        $result = '';

        foreach ($this->chars($picture) as $char) {
            if ($char === '#') {
                $result .= '#';

                continue;
            }

            $digit = $this->digitValue($char, $zeroDigit);
            if ($digit !== null || ($char >= '0' && $char <= '9')) {
                $result .= '0';

                continue;
            }

            $result .= $char;
        }

        return $result;
    }

    private function isActive(string $char, string $zeroDigit): bool
    {
        return $char === '#' || $char === '.' || $char === ',' || $char === 'e' || $char === '%' || $this->digitValue($char, $zeroDigit) !== null || ($char >= '0' && $char <= '9');
    }

    private function mandatoryDigitCount(string $picture): int
    {
        return substr_count($picture, '0');
    }

    private function digitCount(string $picture): int
    {
        return substr_count($picture, '0') + substr_count($picture, '#');
    }

    /**
     * @return list<array{0: string, 1: int}>
     */
    private function groupingSpec(string $picture, bool $integer): array
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
            return [];
        }

        if ($integer) {
            $lastDigit = max($digitPositions);
            foreach ($chars as $index => $char) {
                if ($index < $lastDigit && $char !== '#' && $char !== '0') {
                    $separators[] = [$char, $this->countDigitsAfter($chars, $index)];
                }
            }

            return $separators;
        }

        $firstDigit = min($digitPositions);
        foreach ($chars as $index => $char) {
            if ($index > $firstDigit && $char !== '#' && $char !== '0') {
                $separators[] = [$char, $this->countDigitsBefore($chars, $index)];
            }
        }

        return $separators;
    }

    /**
     * @param  list<array{0: string, 1: int}>  $separators
     */
    private function applyIntegerGrouping(string $digits, array $separators): string
    {
        $spec = array_reverse($separators);
        $sizes = [];
        $previous = 0;
        foreach ($spec as [, $distance]) {
            $sizes[] = $distance - $previous;
            $previous = $distance;
        }

        $repeat = count($spec) === 1 || (count(array_unique($sizes)) === 1 && count(array_unique(array_column($spec, 0))) === 1);
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

        $lastIndex = array_key_last($spec);
        $size = $spec[$lastIndex][1] - ($lastIndex > 0 ? $spec[$lastIndex - 1][1] : 0);
        $separator = $spec[$lastIndex][0];

        while (strlen($remaining) > $size) {
            $result = $separator.substr($remaining, -$size).$result;
            $remaining = substr($remaining, 0, -$size);
        }

        return $remaining.$result;
    }

    /**
     * @param  list<array{0: string, 1: int}>  $separators
     */
    private function applyFractionGrouping(string $digits, array $separators): string
    {
        $result = $digits;
        $inserted = 0;

        foreach ($separators as [$separator, $distance]) {
            if (strlen($digits) <= $distance) {
                continue;
            }

            $position = $distance + $inserted;
            $result = substr($result, 0, $position).$separator.substr($result, $position);
            $inserted += strlen($separator);
        }

        return $result;
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

    /**
     * @param  list<string>  $chars
     */
    private function countDigitsBefore(array $chars, int $position): int
    {
        $count = 0;

        for ($index = 0; $index < $position; $index++) {
            if ($chars[$index] === '#' || $chars[$index] === '0') {
                $count++;
            }
        }

        return $count;
    }

    private function localizeDigits(string $value, string $zeroDigit): string
    {
        if ($zeroDigit === '0') {
            return $value;
        }

        $result = '';

        foreach (str_split($value) as $char) {
            $result .= ctype_digit($char) ? mb_chr(mb_ord($zeroDigit, 'UTF-8') + (int) $char, 'UTF-8') : $char;
        }

        return $result;
    }

    private function digitValue(string $char, string $zeroDigit): ?int
    {
        $delta = mb_ord($char, 'UTF-8') - mb_ord($zeroDigit, 'UTF-8');

        return $delta >= 0 && $delta <= 9 ? $delta : null;
    }

    /**
     * @return list<string>
     */
    private function chars(string $value): array
    {
        return preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
