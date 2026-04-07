<?php

namespace JsonataPhp\Formatters;

class NumberFormatter
{
    public function format(int|float $value, string $picture): string
    {
        $percent = str_contains($picture, '%');
        $pattern = str_replace('%', '', $picture);
        $scaled = $percent ? $value * 100 : $value;

        $negative = $scaled < 0;
        $absolute = abs($scaled);
        $decimalPos = strrpos($pattern, '.');
        $integerPattern = $decimalPos === false ? $pattern : substr($pattern, 0, $decimalPos);
        $fractionPattern = $decimalPos === false ? '' : substr($pattern, $decimalPos + 1);
        $minFractionDigits = substr_count($fractionPattern, '0');
        $maxFractionDigits = strlen($fractionPattern);
        $rounded = $maxFractionDigits > 0 ? round($absolute, $maxFractionDigits, PHP_ROUND_HALF_EVEN) : round($absolute);
        $formatted = number_format($rounded, $maxFractionDigits, '.', ',');

        [$integerPart, $fractionPart] = array_pad(explode('.', $formatted, 2), 2, '');
        $minIntegerDigits = max(1, substr_count($integerPattern, '0'));
        $integerPart = str_pad(str_replace(',', '', $integerPart), $minIntegerDigits, '0', STR_PAD_LEFT);

        if (str_contains($integerPattern, ',')) {
            $grouping = max(1, strlen($integerPattern) - strrpos($integerPattern, ',') - 1);
            $integerPart = $this->applyGrouping($integerPart, $grouping);
        }

        if ($maxFractionDigits > 0) {
            $fractionPart = rtrim($fractionPart, '0');
            if (strlen($fractionPart) < $minFractionDigits) {
                $fractionPart = str_pad($fractionPart, $minFractionDigits, '0');
            }
        }

        $result = $integerPart;
        if ($fractionPart !== '') {
            $result .= '.'.$fractionPart;
        }

        if ($negative) {
            $result = '-'.$result;
        }

        return $percent ? $result.'%' : $result;
    }

    private function applyGrouping(string $value, int $grouping): string
    {
        $grouped = '';

        while (strlen($value) > $grouping) {
            $grouped = ','.substr($value, -$grouping).$grouped;
            $value = substr($value, 0, -$grouping);
        }

        return $value.$grouped;
    }
}
