<?php

namespace JsonataPhp\Formatters;

use DateTimeImmutable;
use DateTimeZone;
use JsonataPhp\EvaluationException;

class DateTimeFormatter
{
    private const MONTHS = [
        1 => 'January',
        2 => 'February',
        3 => 'March',
        4 => 'April',
        5 => 'May',
        6 => 'June',
        7 => 'July',
        8 => 'August',
        9 => 'September',
        10 => 'October',
        11 => 'November',
        12 => 'December',
    ];

    private const DAYS = [
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
        7 => 'Sunday',
    ];

    private const ORDINAL_WORDS = [
        1 => 'first',
        2 => 'second',
        3 => 'third',
        4 => 'fourth',
        5 => 'fifth',
        6 => 'sixth',
        7 => 'seventh',
        8 => 'eighth',
        9 => 'ninth',
        10 => 'tenth',
        11 => 'eleventh',
        12 => 'twelfth',
        13 => 'thirteenth',
        14 => 'fourteenth',
        15 => 'fifteenth',
        16 => 'sixteenth',
        17 => 'seventeenth',
        18 => 'eighteenth',
        19 => 'nineteenth',
        20 => 'twentieth',
        30 => 'thirtieth',
        40 => 'fortieth',
        50 => 'fiftieth',
        60 => 'sixtieth',
        70 => 'seventieth',
        80 => 'eightieth',
        90 => 'ninetieth',
    ];

    public function __construct(private readonly IntegerFormatter $integerFormatter) {}

    public function parseIsoMillis(string $value): int
    {
        $pattern = '/^(?<year>\d{4})(?:-(?<month>\d{2})(?:-(?<day>\d{2})(?:T(?<hour>\d{2}):(?<minute>\d{2})(?::(?<second>\d{2})(?:\.(?<fraction>\d{1,9}))?)?(?<tz>Z|[+-]\d{2}:?\d{2})?)?)?)?$/';

        if (preg_match($pattern, $value, $matches) !== 1) {
            throw new EvaluationException(
                sprintf('Error D3110: The argument of the toMillis function must be an ISO 8601 formatted timestamp. Given "%s"', $value),
                'D3110'
            );
        }

        return $this->partsToMillis([
            'year' => (int) $matches['year'],
            'month' => isset($matches['month']) && $matches['month'] !== '' ? (int) $matches['month'] : 1,
            'day' => isset($matches['day']) && $matches['day'] !== '' ? (int) $matches['day'] : 1,
            'hour' => isset($matches['hour']) && $matches['hour'] !== '' ? (int) $matches['hour'] : 0,
            'minute' => isset($matches['minute']) && $matches['minute'] !== '' ? (int) $matches['minute'] : 0,
            'second' => isset($matches['second']) && $matches['second'] !== '' ? (int) $matches['second'] : 0,
            'millisecond' => isset($matches['fraction']) && $matches['fraction'] !== '' ? (int) substr(str_pad($matches['fraction'], 3, '0'), 0, 3) : 0,
            'timezone' => isset($matches['tz']) && $matches['tz'] !== '' ? $this->normalizeParsedTimezone($matches['tz']) : '+00:00',
        ]);
    }

    public function parseMillis(string $value, string $picture): ?int
    {
        $pattern = $this->pictureToRegex($picture);
        if ($pattern === null || preg_match($pattern['regex'], $value, $matches) !== 1) {
            return null;
        }

        $parts = [
            'year' => null,
            'month' => null,
            'day' => null,
            'dayOfYear' => null,
            'hour' => null,
            'hour12' => null,
            'minute' => null,
            'second' => null,
            'millisecond' => null,
            'ampm' => null,
            'timezone' => '+00:00',
        ];

        foreach ($pattern['components'] as $group => $component) {
            if (! isset($matches[$group]) || $matches[$group] === '') {
                continue;
            }

            $raw = $matches[$group];
            $valuePart = $this->parseComponentValue($raw, $component);
            if ($valuePart === null) {
                return null;
            }

            match ($component['name']) {
                'Y', 'X' => $parts['year'] = $valuePart,
                'M' => $parts['month'] = $valuePart,
                'D' => $parts['day'] = $valuePart,
                'd' => $parts['dayOfYear'] = $valuePart,
                'H' => $parts['hour'] = $valuePart,
                'h' => $parts['hour12'] = $valuePart,
                'm' => $parts['minute'] = $valuePart,
                's' => $parts['second'] = $valuePart,
                'f' => $parts['millisecond'] = (int) substr(str_pad((string) $valuePart, 3, '0'), 0, 3),
                'P' => $parts['ampm'] = strtolower($raw),
                'Z', 'z' => $parts['timezone'] = $this->normalizeParsedTimezone($raw),
                default => null,
            };
        }

        if ($this->requiresUnsupportedIsoWeekParsing($pattern['components'])) {
            throw new EvaluationException(
                'Error D3136: The date/time picture string is missing specifiers required to parse the timestamp',
                'D3136'
            );
        }

        if ($parts['year'] === null && ($parts['month'] !== null || $parts['day'] !== null || $parts['dayOfYear'] !== null)) {
            throw new EvaluationException(
                'Error D3136: The date/time picture string is missing specifiers required to parse the timestamp',
                'D3136'
            );
        }

        if ($parts['year'] !== null && $parts['day'] !== null && $parts['month'] === null) {
            throw new EvaluationException(
                'Error D3136: The date/time picture string is missing specifiers required to parse the timestamp',
                'D3136'
            );
        }

        if ($parts['year'] === null) {
            if ($parts['hour'] === null && $parts['hour12'] === null) {
                return null;
            }

            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $parts['year'] = (int) $now->format('Y');
            $parts['month'] = (int) $now->format('m');
            $parts['day'] = (int) $now->format('d');
        }

        $hour = $parts['hour'] ?? 0;
        if ($parts['hour12'] !== null) {
            $hour = $parts['hour12'] % 12;
            if ($parts['ampm'] === 'pm') {
                $hour += 12;
            }
        }

        $dateParts = [
            'year' => $parts['year'],
            'month' => $parts['month'] ?? 1,
            'day' => $parts['day'] ?? 1,
            'hour' => $hour,
            'minute' => $parts['minute'] ?? 0,
            'second' => $parts['second'] ?? 0,
            'millisecond' => $parts['millisecond'] ?? 0,
            'timezone' => $parts['timezone'],
        ];

        if ($parts['dayOfYear'] !== null) {
            $base = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', sprintf('%04d-01-01 00:00:00.000000', $parts['year']), new DateTimeZone('UTC'));
            if (! $base) {
                return null;
            }

            $base = $base->modify('+'.($parts['dayOfYear'] - 1).' days');
            $dateParts['month'] = (int) $base->format('m');
            $dateParts['day'] = (int) $base->format('d');
        }

        return $this->partsToMillis($dateParts);
    }

    public function format(DateTimeImmutable $date, string $picture, string $timezone): ?string
    {
        $this->assertBalancedPicture($picture);

        $output = '';
        $length = strlen($picture);

        for ($index = 0; $index < $length; $index++) {
            $char = $picture[$index];

            if ($char === '[') {
                if (($picture[$index + 1] ?? '') === '[') {
                    $output .= '[';
                    $index++;

                    continue;
                }

                $end = strpos($picture, ']', $index + 1);
                if ($end === false) {
                    throw new EvaluationException(
                        "Error D3135: No matching closing bracket ']' in date/time picture string",
                        'D3135'
                    );
                }

                $component = $this->parseComponent(substr($picture, $index + 1, $end - $index - 1), true);
                if ($component === null) {
                    return null;
                }

                $formatted = $this->formatComponent($date, $component, $timezone);
                if ($formatted === null) {
                    return null;
                }

                $output .= $formatted;
                $index = $end;

                continue;
            }

            if ($char === ']' && ($picture[$index + 1] ?? '') === ']') {
                $output .= ']';
                $index++;

                continue;
            }

            $output .= $char;
        }

        return $output;
    }

    private function pictureToRegex(string $picture): ?array
    {
        $this->assertBalancedPicture($picture);

        $regex = '';
        $components = [];
        $length = strlen($picture);
        $count = 0;

        for ($index = 0; $index < $length; $index++) {
            $char = $picture[$index];

            if ($char === '[') {
                if (($picture[$index + 1] ?? '') === '[') {
                    $regex .= preg_quote('[', '/');
                    $index++;

                    continue;
                }

                $end = strpos($picture, ']', $index + 1);
                if ($end === false) {
                    return null;
                }

                $component = $this->parseComponent(substr($picture, $index + 1, $end - $index - 1), true);
                if ($component === null) {
                    return null;
                }

                if (in_array($component['name'], ['F', 'E', 'C', 'W', 'w', 'x'], true)) {
                    $regex .= '(?:.+?)';
                } else {
                    $group = 'p'.$count++;
                    $regex .= '(?<'.$group.'>'.$this->componentRegex($component).')';
                    $components[$group] = $component;
                }

                $index = $end;

                continue;
            }

            if ($char === ']' && ($picture[$index + 1] ?? '') === ']') {
                $regex .= preg_quote(']', '/');
                $index++;

                continue;
            }

            $regex .= preg_quote($char, '/');
        }

        return ['regex' => '/^'.$regex.'$/iu', 'components' => $components];
    }

    private function parseComponent(string $component, bool $strict = false): ?array
    {
        $normalized = preg_replace('/\s+/', '', $component) ?? '';

        if (preg_match('/^([A-Za-z]+)(.*)$/', $normalized, $matches) !== 1) {
            if ($strict) {
                throw new EvaluationException(
                    sprintf('Error D3132: Unknown component specifier "%s" in date/time picture string', $normalized),
                    'D3132'
                );
            }

            return null;
        }

        $name = $matches[1][0];
        if (! in_array($name, ['Y', 'M', 'D', 'd', 'F', 'W', 'w', 'X', 'x', 'H', 'h', 'm', 's', 'f', 'Z', 'z', 'P', 'E', 'C'], true)) {
            if ($strict) {
                throw new EvaluationException(
                    sprintf('Error D3132: Unknown component specifier "%s" in date/time picture string', $name),
                    'D3132'
                );
            }

            return null;
        }

        $tail = substr($normalized, 1);
        $width = null;

        if (preg_match('/^(.*),(?:(\d+|\*)-(\d+|\*)|(\d+)(?:-(\d+|\*))?)$/', $tail, $widthMatches) === 1) {
            $tail = $widthMatches[1];
            $min = $widthMatches[2] !== '' ? $widthMatches[2] : $widthMatches[4];
            $max = $widthMatches[3] !== '' ? $widthMatches[3] : ($widthMatches[5] ?? '');
            $width = [
                'min' => $min === '*' ? null : (int) $min,
                'max' => $max === '*' || $max === '' ? null : (int) $max,
            ];
        }

        if ($strict && str_contains($tail, 'N') && ! in_array($name, ['M', 'F', 'P', 'x'], true)) {
            throw new EvaluationException(
                sprintf('Error D3133: The \'name\' modifier can only be applied to months and days in the date/time picture string, not "%s"', $name),
                'D3133'
            );
        }

        return [
            'name' => $name,
            'marker' => $normalized,
            'presentation' => $tail === '' ? '1' : $tail,
            'ordinal' => str_ends_with($tail, 'o'),
            'width' => $width,
        ];
    }

    private function componentRegex(array $component): string
    {
        if (in_array($component['name'], ['M', 'F'], true) && str_contains($component['presentation'], 'N')) {
            return '[A-Za-z]+';
        }

        if ($component['name'] === 'P') {
            return '[AaPp][Mm]';
        }

        if (in_array($component['name'], ['Z', 'z'], true)) {
            return '(?:GMT)?[+-]\d{1,2}(?::?\d{2})?|Z';
        }

        if (str_contains($component['presentation'], 'w') || str_contains($component['presentation'], 'W')) {
            return '[A-Za-z,\-\s]+?';
        }

        if (str_contains($component['presentation'], 'I') || str_contains($component['presentation'], 'i')) {
            return '[IVXLCDMivxlcdm]+';
        }

        if (str_contains($component['presentation'], 'A') || str_contains($component['presentation'], 'a')) {
            return '[A-Za-z]+';
        }

        if (preg_match('/^[#0-9]+$/', $component['presentation']) === 1) {
            $digits = strlen(str_replace('#', '', $component['presentation']));
            if ($digits > 1) {
                return '\d{'.$digits.'}(?:st|nd|rd|th)?';
            }
        }

        if (($component['width']['max'] ?? null) !== null) {
            return '\d{1,'.$component['width']['max'].'}(?:st|nd|rd|th)?';
        }

        return '\d+(?:st|nd|rd|th)?';
    }

    private function parseComponentValue(string $raw, array $component): int|string|null
    {
        if ($component['name'] === 'M' && str_contains($component['presentation'], 'N')) {
            return $this->parseMonthName($raw);
        }

        if ($component['name'] === 'P') {
            return strtolower($raw);
        }

        if (in_array($component['name'], ['Z', 'z'], true)) {
            return $raw;
        }

        $value = preg_replace('/(?<=\d)(st|nd|rd|th)$/i', '', trim($raw)) ?? '';

        try {
            if (str_contains($component['presentation'], 'w') || str_contains($component['presentation'], 'W')) {
                return $this->wordsToNumber($value);
            }

            if (str_contains($component['presentation'], 'I') || str_contains($component['presentation'], 'i')) {
                return $this->integerFormatter->parse($value, 'I');
            }

            if (str_contains($component['presentation'], 'A') || str_contains($component['presentation'], 'a')) {
                return $this->integerFormatter->parse($value, ctype_upper($value[0] ?? 'A') ? 'A' : 'a');
            }
        } catch (EvaluationException) {
            return null;
        }

        return ctype_digit($value) ? (int) $value : null;
    }

    private function formatComponent(DateTimeImmutable $date, array $component, string $timezone): ?string
    {
        $name = $component['name'];

        if ($name === 'E' || $name === 'C') {
            return 'ISO';
        }

        if ($name === 'P') {
            $value = (int) $date->format('G') < 12 ? 'am' : 'pm';

            return str_contains($component['presentation'], 'N') ? strtoupper($value) : $value;
        }

        if ($name === 'Z' || $name === 'z') {
            $offset = $this->formatTimezone($timezone, $component['marker']);

            return $offset === null ? null : ($name === 'z' ? 'GMT'.$offset : $offset);
        }

        if ($name === 'M' && str_contains($component['presentation'], 'N')) {
            return $this->formatName(self::MONTHS[(int) $date->format('n')], $component);
        }

        if ($name === 'F' && $component['marker'] === 'F') {
            return strtolower(self::DAYS[(int) $date->format('N')]);
        }

        if ($name === 'F' && str_contains($component['presentation'], 'N')) {
            return $this->formatName(self::DAYS[(int) $date->format('N')], $component);
        }

        if ($name === 'x') {
            $thursday = $this->weekMonthAnchor($date);
            if (str_contains($component['presentation'], 'N')) {
                return $this->formatName(self::MONTHS[(int) $thursday->format('n')], $component);
            }

            return $this->formatNumber((int) $thursday->format('n'), $component);
        }

        $value = match ($name) {
            'Y' => (int) $date->format('Y'),
            'X' => (int) $date->format('o'),
            'M' => (int) $date->format('n'),
            'D' => (int) $date->format('j'),
            'd' => (int) $date->format('z') + 1,
            'F' => (int) $date->format('N'),
            'W' => (int) $date->format('W'),
            'w' => intdiv(((int) $this->weekMonthAnchor($date)->format('j')) - 1, 7) + 1,
            'H' => (int) $date->format('G'),
            'h' => (int) $date->format('g'),
            'm' => (int) $date->format('i'),
            's' => (int) $date->format('s'),
            'f' => (int) $date->format('v'),
            default => null,
        };

        return $value === null ? null : $this->formatNumber($value, $component);
    }

    private function formatNumber(int $value, array $component): string
    {
        $presentation = rtrim($component['presentation'], 'o');

        if (($presentation === 'w' || $presentation === 'W' || $presentation === 'Ww') && $component['ordinal']) {
            $formatted = $this->ordinalWords($value, $presentation);
        } elseif ($presentation === 'w' || $presentation === 'W' || $presentation === 'Ww') {
            $formatted = $this->integerFormatter->format($value, $presentation);
        } elseif ($presentation === 'I' || $presentation === 'i' || $presentation === 'A' || $presentation === 'a') {
            $formatted = $this->integerFormatter->format($value, $presentation);
        } else {
            $picture = preg_replace('/[1-9]/', '0', str_replace(['#', '*'], '', $presentation)) ?? '';
            if ($component['presentation'] === '1' && in_array($component['name'], ['m', 's'], true)) {
                $picture = '00';
            }

            $picture = $picture === '' ? '1' : $picture;
            $formatted = $this->integerFormatter->format($value, $picture);
        }

        if (($component['width']['max'] ?? null) !== null && $component['width']['max'] === $component['width']['min']) {
            $formatted = substr($formatted, -$component['width']['max']);
        }

        if ($component['name'] === 'Y' && $component['presentation'] === '1' && ($component['width']['min'] ?? null) !== null) {
            $formatted = substr($formatted, -$component['width']['min']);
        }

        if (($component['width']['min'] ?? null) !== null && strlen($formatted) < $component['width']['min']) {
            $formatted = str_pad($formatted, $component['width']['min'], '0', STR_PAD_LEFT);
        }

        if ($component['ordinal'] && ! str_contains($presentation, 'w') && ! str_contains($presentation, 'W')) {
            $formatted .= $this->ordinalSuffix($value);
        }

        return $formatted;
    }

    private function formatName(string $name, array $component): string
    {
        if (($component['width']['max'] ?? null) !== null && $component['width']['max'] === $component['width']['min']) {
            $name = substr($name, 0, $component['width']['max']);
        }

        return match (true) {
            str_contains($component['presentation'], 'Nn') => $name,
            str_contains($component['presentation'], 'N') => strtoupper($name),
            default => strtolower($name),
        };
    }

    private function formatTimezone(string $timezone, string $marker): ?string
    {
        if (preg_match('/^Z01:?01t$/', $marker) === 1 && in_array($timezone, ['UTC', '+00:00', '+0000', '0000'], true)) {
            return 'Z';
        }

        if (preg_match('/^Z010101t$/', $marker) === 1) {
            throw new EvaluationException(
                'Error D3134: The timezone integer format specifier cannot have more than four digits',
                'D3134'
            );
        }

        $normalized = $this->normalizeParsedTimezone($timezone);
        if ($normalized === 'Z') {
            $normalized = '+00:00';
        }

        if (preg_match('/^([+-])(\d{2}):(\d{2})$/', $normalized, $matches) !== 1) {
            return '+00:00';
        }

        [$all, $sign, $hours, $minutes] = $matches;

        return match (true) {
            str_contains($marker, '0101') => $sign.$hours.$minutes,
            str_contains($marker, '0') && $minutes === '00' => $sign.(string) ((int) $hours),
            str_contains($marker, '0') => $sign.(string) ((int) $hours).':'.$minutes,
            default => $sign.$hours.':'.$minutes,
        };
    }

    private function normalizeParsedTimezone(string $timezone): string
    {
        $timezone = trim($timezone);
        $timezone = preg_replace('/^GMT/i', '', $timezone) ?? $timezone;

        if ($timezone === 'Z') {
            return '+00:00';
        }

        if (preg_match('/^([+-])(\d{1,2})(?::?(\d{2}))?$/', $timezone, $matches) === 1) {
            return sprintf('%s%02d:%02d', $matches[1], (int) $matches[2], isset($matches[3]) ? (int) $matches[3] : 0);
        }

        return $timezone;
    }

    private function partsToMillis(array $parts): int
    {
        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s.u P',
            sprintf(
                '%04d-%02d-%02d %02d:%02d:%02d.%06d %s',
                $parts['year'],
                $parts['month'],
                $parts['day'],
                $parts['hour'],
                $parts['minute'],
                $parts['second'],
                $parts['millisecond'] * 1000,
                $parts['timezone']
            ),
            new DateTimeZone('UTC')
        );

        if (
            ! $date
            || ! checkdate($parts['month'], $parts['day'], $parts['year'])
            || $parts['hour'] < 0 || $parts['hour'] > 23
            || $parts['minute'] < 0 || $parts['minute'] > 59
            || $parts['second'] < 0 || $parts['second'] > 59
            || $parts['millisecond'] < 0 || $parts['millisecond'] > 999
        ) {
            throw new EvaluationException(
                'Error D3110: The timestamp could not be parsed.',
                'D3110'
            );
        }

        $date = $date->setTimezone(new DateTimeZone('UTC'));

        return ((int) $date->format('U')) * 1000 + (int) $date->format('v');
    }

    /**
     * @param  array<string, array<string, mixed>>  $components
     */
    private function requiresUnsupportedIsoWeekParsing(array $components): bool
    {
        foreach ($components as $component) {
            if (in_array($component['name'], ['X', 'x', 'W', 'w'], true)) {
                return true;
            }
        }

        return false;
    }

    private function assertBalancedPicture(string $picture): void
    {
        $length = strlen($picture);

        for ($index = 0; $index < $length; $index++) {
            if ($picture[$index] !== '[') {
                continue;
            }

            if (($picture[$index + 1] ?? '') === '[') {
                $index++;

                continue;
            }

            if (strpos($picture, ']', $index + 1) === false) {
                throw new EvaluationException(
                    "Error D3135: No matching closing bracket ']' in date/time picture string",
                    'D3135'
                );
            }
        }
    }

    private function weekMonthAnchor(DateTimeImmutable $date): DateTimeImmutable
    {
        return $date->modify(((int) $date->format('N') <= 4 ? '+' : '-').abs(4 - (int) $date->format('N')).' days');
    }

    private function parseMonthName(string $value): ?int
    {
        $value = strtolower($value);

        foreach (self::MONTHS as $number => $month) {
            if (str_starts_with(strtolower($month), $value)) {
                return $number;
            }
        }

        return null;
    }

    private function wordsToNumber(string $value): ?int
    {
        $normalized = strtolower(trim($value));
        $ordinalLookup = array_flip(self::ORDINAL_WORDS);
        if (isset($ordinalLookup[$normalized])) {
            return $ordinalLookup[$normalized];
        }

        try {
            return $this->integerFormatter->parse($normalized, 'w');
        } catch (EvaluationException) {
            $parts = explode('-', $normalized);
            $parts = preg_split('/[\s-]+/', $normalized) ?: [];
            if (count($parts) > 1) {
                $last = array_pop($parts);
                if (isset($ordinalLookup[$last])) {
                    return $this->integerFormatter->parse(implode(' ', $parts), 'w') + $ordinalLookup[$last];
                }
            }
        }

        return null;
    }

    private function ordinalWords(int $value, string $presentation): string
    {
        if (isset(self::ORDINAL_WORDS[$value])) {
            $words = self::ORDINAL_WORDS[$value];
        } else {
            $base = intdiv($value, 10) * 10;
            $remainder = $value % 10;
            $words = $this->integerFormatter->format($base, 'w').'-'.(self::ORDINAL_WORDS[$remainder] ?? $this->integerFormatter->format($remainder, 'w'));
        }

        return match ($presentation) {
            'W' => strtoupper($words),
            'Ww' => ucwords($words),
            default => $words,
        };
    }

    private function ordinalSuffix(int $value): string
    {
        if ($value % 100 >= 11 && $value % 100 <= 13) {
            return 'th';
        }

        return match ($value % 10) {
            1 => 'st',
            2 => 'nd',
            3 => 'rd',
            default => 'th',
        };
    }
}
