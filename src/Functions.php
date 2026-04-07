<?php

namespace JsonataPhp;

use Closure;
use JsonataPhp\Builtins\BuiltinDefinition;
use JsonataPhp\Builtins\BuiltinSupport;
use JsonataPhp\Builtins\RegistersCollectionBuiltins;
use JsonataPhp\Builtins\RegistersDatetimeBuiltins;
use JsonataPhp\Builtins\RegistersEncodingBuiltins;
use JsonataPhp\Builtins\RegistersMetaBuiltins;
use JsonataPhp\Builtins\RegistersNumericBuiltins;
use JsonataPhp\Builtins\RegistersObjectBuiltins;
use JsonataPhp\Builtins\RegistersStringBuiltins;
use JsonataPhp\Formatters\IntegerFormatter;
use JsonataPhp\Formatters\NumberFormatter;
use ReflectionFunction;

class Functions
{
    use RegistersCollectionBuiltins;
    use RegistersDatetimeBuiltins;
    use RegistersEncodingBuiltins;
    use RegistersMetaBuiltins;
    use RegistersNumericBuiltins;
    use RegistersObjectBuiltins;
    use RegistersStringBuiltins;

    protected BuiltinSupport $support;

    public function __construct(
        private readonly Lexer $lexer,
        private readonly Parser $parser,
        protected readonly IntegerFormatter $integerFormatter,
        protected readonly NumberFormatter $numberFormatter,
    ) {}

    /**
     * @param  array<string, mixed>  $rootContext
     * @return array<string, Closure>
     */
    public function defaultEnvironment(Evaluator $evaluator, array $rootContext): array
    {
        $this->support = new BuiltinSupport;

        $definitions = [
            ...$this->collectionBuiltinDefinitions($evaluator, $rootContext),
            ...$this->datetimeBuiltinDefinitions($evaluator, $rootContext),
            ...$this->encodingBuiltinDefinitions($evaluator, $rootContext),
            ...$this->metaBuiltinDefinitions($evaluator, $rootContext),
            ...$this->numericBuiltinDefinitions($evaluator, $rootContext),
            ...$this->objectBuiltinDefinitions($evaluator, $rootContext),
            ...$this->stringBuiltinDefinitions($evaluator, $rootContext),
        ];

        $environment = [];

        foreach ($definitions as $definition) {
            $wrapper = function (array $arguments, mixed $context = null) use ($definition, $evaluator): mixed {
                return $definition->invoke(
                    array_map(fn (mixed $argument): mixed => $evaluator->normalizeValuePublic($argument), $arguments),
                    $evaluator->normalizeValuePublic($context),
                    $evaluator
                );
            };

            $arity = $definition->arity();
            if ($arity === null) {
                $arity = max(0, (new ReflectionFunction($definition->implementation))->getNumberOfParameters());
            }

            $environment['$'.$definition->name] = $this->support->registerArity($wrapper, $arity);
        }

        return $environment;
    }

    public function registerFunctionArity(Closure $function, int $arity): Closure
    {
        return $this->support->registerArity($function, $arity);
    }

    public function functionArity(Closure $function): int
    {
        return $this->support->functionArity($function);
    }

    protected function builtin(string $name, Closure $implementation, ?string $signature = null): BuiltinDefinition
    {
        return new BuiltinDefinition($name, $implementation, $signature);
    }

    protected function lookupValue(mixed $input, string $key, Evaluator $evaluator): mixed
    {
        if (is_array($input) && array_is_list($input)) {
            $values = [];

            foreach ($input as $item) {
                $value = $this->lookupValue($item, $key, $evaluator);

                if ($value === null || $evaluator->isMissing($value)) {
                    continue;
                }

                if (is_array($value) && array_is_list($value)) {
                    foreach ($value as $nested) {
                        $values[] = $nested;
                    }
                } else {
                    $values[] = $value;
                }
            }

            return $values === [] ? null : $evaluator->collapseSequence($values);
        }

        if (is_array($input) && array_key_exists($key, $input)) {
            return $input[$key];
        }

        return null;
    }

    protected function keysOf(mixed $input): mixed
    {
        if (! is_array($input)) {
            return [];
        }

        if (array_is_list($input)) {
            $keys = [];

            foreach ($input as $item) {
                if (! is_array($item)) {
                    continue;
                }

                foreach (array_keys($item) as $key) {
                    $keys[$key] = true;
                }
            }

            $result = array_keys($keys);

            return count($result) === 1 ? $result[0] : $result;
        }

        $result = array_keys($input);

        return count($result) === 1 ? $result[0] : $result;
    }

    /**
     * @param  array<int, mixed>  $items
     * @return array<string, mixed>
     */
    protected function mergeObjects(array $items): array
    {
        $merged = [];

        foreach ($items as $item) {
            if (! is_array($item) || array_is_list($item)) {
                throw new EvaluationException(
                    'Error T0412: $merge expects object values.',
                    'T0412'
                );
            }

            foreach ($item as $key => $value) {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }

    /**
     * @param  array<int, mixed>  $items
     */
    protected function distinctValues(array $items, Evaluator $evaluator): mixed
    {
        $seen = [];
        $result = [];

        foreach ($items as $item) {
            $hash = is_scalar($item) || $item === null
                ? gettype($item).':'.(string) $item
                : 'json:'.$evaluator->stringifyPublic($item);

            if (array_key_exists($hash, $seen)) {
                continue;
            }

            $seen[$hash] = true;
            $result[] = $item;
        }

        return $evaluator->collapseSequence($result);
    }

    /**
     * @param  array<int, mixed>  $items
     * @return array<int, int|float>
     */
    protected function numericSequence(array $items): array
    {
        return array_map(fn (mixed $item): int|float => $this->toNumber($item), $items);
    }

    /**
     * @param  array<int, mixed>  $items
     */
    protected function minOrMax(array $items, string $mode): int|float|null
    {
        $values = $this->numericSequence($items);

        if ($values === []) {
            return null;
        }

        return $mode === 'min' ? min($values) : max($values);
    }

    protected function toNumber(mixed $value): int|float
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if (is_string($value) && is_numeric($value)) {
            return str_contains($value, '.') ? (float) $value : (int) $value;
        }

        throw new EvaluationException(
            'Error T0412: Expected a numeric value.',
            'T0412'
        );
    }

    protected function isRegexLiteral(mixed $value): bool
    {
        return $value instanceof RegexPattern;
    }

    protected function toPregPattern(RegexPattern $regex): string
    {
        return $regex->toPcre();
    }

    protected function toMillis(string $value): int
    {
        try {
            $date = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            throw new EvaluationException(
                'Error D3110: The timestamp could not be parsed.',
                'D3110'
            );
        }

        return ((int) $date->format('U')) * 1000 + (int) $date->format('v');
    }

    protected function fromMillis(int $millis, ?string $picture = null, ?string $timezone = null): string
    {
        $seconds = intdiv($millis, 1000);
        $milliseconds = $millis % 1000;
        $timezone = $timezone === null || $timezone === '' ? 'UTC' : $this->normalizeTimezone($timezone);

        $date = \DateTimeImmutable::createFromFormat(
            'U.u',
            sprintf('%d.%06d', $seconds, $milliseconds * 1000),
            new \DateTimeZone('UTC')
        );

        if (! $date) {
            throw new EvaluationException(
                'Error D3130: The millis value could not be formatted.',
                'D3130'
            );
        }

        $date = $date->setTimezone(new \DateTimeZone($timezone));

        if ($picture === null || $picture === '') {
            return $date->format('Y-m-d\TH:i:s.v\Z');
        }

        return strtr($picture, [
            '[Y0001]' => $date->format('Y'),
            '[M01]' => $date->format('m'),
            '[D01]' => $date->format('d'),
            '[H01]' => $date->format('H'),
            '[h01]' => $date->format('h'),
            '[m01]' => $date->format('i'),
            '[s01]' => $date->format('s'),
            '[f001]' => $date->format('v'),
        ]);
    }

    protected function formatNumber(int|float $value, string $picture): string
    {
        $format = $this->analyzeDigitPicture($picture, true);
        $absolute = abs($value);
        $rounded = round($absolute, $format['max_fraction_digits'], PHP_ROUND_HALF_EVEN);
        $formatted = number_format($rounded, $format['max_fraction_digits'], '.', ',');

        if ($format['grouping'] === null) {
            $formatted = str_replace(',', '', $formatted);
        }

        [$integer, $fraction] = array_pad(explode('.', $formatted, 2), 2, '');

        if (strlen($integer) < $format['min_integer_digits']) {
            $integer = str_pad($integer, $format['min_integer_digits'], '0', STR_PAD_LEFT);
        }

        if ($format['max_fraction_digits'] > 0) {
            $fraction = substr($fraction, 0, $format['max_fraction_digits']);
            $fraction = rtrim($fraction, '0');

            if (strlen($fraction) < $format['min_fraction_digits']) {
                $fraction = str_pad($fraction, $format['min_fraction_digits'], '0');
            }
        } else {
            $fraction = '';
        }

        $sign = $value < 0 ? '-' : '';
        $body = $fraction === '' ? $integer : $integer.'.'.$fraction;

        return $format['prefix'].$sign.$body.$format['suffix'];
    }

    protected function formatInteger(int $value, string $picture): string
    {
        if ($picture === '') {
            return (string) $value;
        }

        if (preg_match('/^[Ii]$/', $picture) === 1) {
            $roman = $this->integerToRoman(abs($value));

            if ($picture === 'i') {
                $roman = strtolower($roman);
            }

            return $value < 0 ? '-'.$roman : $roman;
        }

        if (preg_match('/^[Aa]$/', $picture) === 1) {
            $letters = $this->integerToLetters(abs($value), ctype_upper($picture) ? 'A' : 'a');

            return $value < 0 ? '-'.$letters : $letters;
        }

        $format = $this->analyzeDigitPicture($picture, false);
        $absolute = (string) abs($value);

        if (strlen($absolute) < $format['min_integer_digits']) {
            $absolute = str_pad($absolute, $format['min_integer_digits'], '0', STR_PAD_LEFT);
        }

        if ($format['grouping'] !== null) {
            $absolute = $this->applyGrouping($absolute, $format['grouping']);
        }

        $sign = $value < 0 ? '-' : '';

        return $format['prefix'].$sign.$absolute.$format['suffix'];
    }

    protected function parseInteger(string $value, string $picture): int
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            throw new EvaluationException(
                'Error D3132: The parseInteger function could not parse the supplied value.',
                'D3132'
            );
        }

        $sign = 1;
        if (str_starts_with($trimmed, '-')) {
            $sign = -1;
            $trimmed = substr($trimmed, 1);
        }

        if (preg_match('/^[Ii]$/', $picture) === 1) {
            return $sign * $this->romanToInteger($trimmed);
        }

        if (preg_match('/^[Aa]$/', $picture) === 1) {
            return $sign * $this->lettersToInteger($trimmed);
        }

        $format = $this->analyzeDigitPicture($picture, false);

        if ($format['prefix'] !== '' && str_starts_with($trimmed, $format['prefix'])) {
            $trimmed = substr($trimmed, strlen($format['prefix']));
        }

        if ($format['suffix'] !== '' && str_ends_with($trimmed, $format['suffix'])) {
            $trimmed = substr($trimmed, 0, -strlen($format['suffix']));
        }

        $digits = str_replace(',', '', $trimmed);

        if ($digits === '' || preg_match('/^\d+$/', $digits) !== 1) {
            throw new EvaluationException(
                'Error D3132: The parseInteger function could not parse the supplied value.',
                'D3132'
            );
        }

        return $sign * (int) $digits;
    }

    protected function normalizeTimezone(string $timezone): string
    {
        if (preg_match('/^[+-]\d{4}$/', $timezone) === 1) {
            return substr($timezone, 0, 3).':'.substr($timezone, 3, 2);
        }

        return $timezone;
    }

    protected function evaluateInline(string $expression, mixed $focus, Evaluator $evaluator): mixed
    {
        $tokens = $this->lexer->tokenize($expression);
        $ast = $this->parser->parse($tokens);
        $rootContext = is_array($focus) ? $focus : ['value' => $focus];

        return $evaluator->evaluateWithContext($ast, $focus, $rootContext);
    }

    protected function jsonTypeSymbol(mixed $value, Evaluator $evaluator): string
    {
        if ($this->support->isMissingLike($value, $evaluator)) {
            return 'missing';
        }

        if ($value instanceof RegexPattern) {
            return 'function';
        }

        if ($value instanceof Closure) {
            return 'function';
        }

        return match (true) {
            $value === null => 'null',
            is_string($value) => 'string',
            is_int($value), is_float($value) => 'number',
            is_bool($value) => 'boolean',
            is_array($value) && array_is_list($value) => 'array',
            is_array($value) => 'object',
            default => 'object',
        };
    }

    /**
     * @return array{
     *   prefix: string,
     *   suffix: string,
     *   grouping: int|null,
     *   min_integer_digits: int,
     *   min_fraction_digits: int,
     *   max_fraction_digits: int
     * }
     */
    private function analyzeDigitPicture(string $picture, bool $allowFraction): array
    {
        $primaryPicture = explode(';', $picture)[0];
        $pattern = $allowFraction
            ? '/^(.*?)([#,0,]+)(?:\.([#,0]+))?(.*?)$/'
            : '/^(.*?)([#,0,]+)(.*?)$/';

        if (preg_match($pattern, $primaryPicture, $matches) !== 1) {
            throw new EvaluationException(
                sprintf('Error D3130: Unsupported numeric picture: %s', $picture),
                'D3130'
            );
        }

        $integerPattern = $matches[2];
        $fractionPattern = $allowFraction ? ($matches[3] ?? '') : '';
        $lastComma = strrpos($integerPattern, ',');
        $grouping = $lastComma === false ? null : strlen($integerPattern) - $lastComma - 1;

        return [
            'prefix' => $matches[1],
            'suffix' => $allowFraction ? ($matches[4] ?? '') : ($matches[3] ?? ''),
            'grouping' => $grouping,
            'min_integer_digits' => substr_count($integerPattern, '0'),
            'min_fraction_digits' => substr_count($fractionPattern, '0'),
            'max_fraction_digits' => strlen($fractionPattern),
        ];
    }

    private function applyGrouping(string $digits, int $grouping): string
    {
        if ($grouping <= 0 || strlen($digits) <= $grouping) {
            return $digits;
        }

        $parts = [];

        while ($digits !== '') {
            array_unshift($parts, substr($digits, -$grouping));
            $digits = substr($digits, 0, -$grouping);
        }

        return implode(',', $parts);
    }

    private function integerToRoman(int $value): string
    {
        if ($value === 0) {
            return '0';
        }

        $map = [
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

        $result = '';

        foreach ($map as $number => $symbol) {
            while ($value >= $number) {
                $result .= $symbol;
                $value -= $number;
            }
        }

        return $result;
    }

    private function romanToInteger(string $value): int
    {
        $roman = strtoupper($value);
        $map = ['I' => 1, 'V' => 5, 'X' => 10, 'L' => 50, 'C' => 100, 'D' => 500, 'M' => 1000];
        $total = 0;
        $previous = 0;

        for ($index = strlen($roman) - 1; $index >= 0; $index--) {
            $symbol = $roman[$index];
            $current = $map[$symbol] ?? null;

            if ($current === null) {
                throw new EvaluationException(
                    'Error D3132: The parseInteger function could not parse the supplied value.',
                    'D3132'
                );
            }

            if ($current < $previous) {
                $total -= $current;
            } else {
                $total += $current;
                $previous = $current;
            }
        }

        return $total;
    }

    private function integerToLetters(int $value, string $start): string
    {
        if ($value <= 0) {
            return '0';
        }

        $result = '';
        $base = ord($start);

        while ($value > 0) {
            $value--;
            $result = chr($base + ($value % 26)).$result;
            $value = intdiv($value, 26);
        }

        return $result;
    }

    private function lettersToInteger(string $value): int
    {
        if (preg_match('/^[A-Za-z]+$/', $value) !== 1) {
            throw new EvaluationException(
                'Error D3132: The parseInteger function could not parse the supplied value.',
                'D3132'
            );
        }

        $result = 0;

        foreach (str_split(strtoupper($value)) as $character) {
            $result = ($result * 26) + (ord($character) - 64);
        }

        return $result;
    }
}
