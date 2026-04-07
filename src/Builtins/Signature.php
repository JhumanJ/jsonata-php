<?php

namespace JsonataPhp\Builtins;

use Closure;
use JsonataPhp\EvaluationException;
use JsonataPhp\Evaluator;

class Signature
{
    /**
     * @param  array<int, array<string, mixed>>  $parameters
     */
    private function __construct(
        private readonly string $definition,
        private readonly array $parameters,
        private readonly string $pattern,
    ) {}

    public static function parse(string $definition): self
    {
        $position = 1;
        $parameters = [];

        while ($position < strlen($definition)) {
            $symbol = $definition[$position];

            if ($symbol === ':') {
                break;
            }

            switch ($symbol) {
                case 's':
                case 'n':
                case 'b':
                case 'l':
                case 'o':
                    $parameters[] = [
                        'regex' => '['.$symbol.'m]',
                        'type' => $symbol,
                    ];
                    break;
                case 'a':
                    $parameters[] = [
                        'regex' => '[asnblfom]',
                        'type' => 'a',
                        'array' => true,
                    ];
                    break;
                case 'f':
                    $parameters[] = [
                        'regex' => 'f',
                        'type' => 'f',
                    ];
                    break;
                case 'j':
                    $parameters[] = [
                        'regex' => '[asnblom]',
                        'type' => 'j',
                    ];
                    break;
                case 'x':
                    $parameters[] = [
                        'regex' => '[asnblfom]',
                        'type' => 'x',
                    ];
                    break;
                case '-':
                    $lastIndex = array_key_last($parameters);
                    if ($lastIndex === null) {
                        break;
                    }

                    $parameters[$lastIndex]['context'] = true;
                    $parameters[$lastIndex]['contextRegex'] = '/^'.$parameters[$lastIndex]['regex'].'$/';
                    $parameters[$lastIndex]['regex'] .= '?';
                    break;
                case '?':
                case '+':
                    $lastIndex = array_key_last($parameters);
                    if ($lastIndex === null) {
                        break;
                    }

                    $parameters[$lastIndex]['regex'] .= $symbol === '+' ? '+?' : $symbol;
                    break;
                case '(':
                    $endParen = self::findClosingBracket($definition, $position, '(', ')');
                    $choice = substr($definition, $position + 1, $endParen - $position - 1);
                    if (str_contains($choice, '<')) {
                        throw new EvaluationException(
                            'Error S0402: Choice groups containing parameterized types are not supported.',
                            'S0402'
                        );
                    }

                    $parameters[] = [
                        'regex' => '['.$choice.'m]',
                        'type' => '('.$choice.')',
                    ];
                    $position = $endParen;
                    break;
                case '<':
                    $lastIndex = array_key_last($parameters);
                    if ($lastIndex === null || ! in_array($parameters[$lastIndex]['type'] ?? null, ['a', 'f'], true)) {
                        throw new EvaluationException(
                            'Error S0401: Type parameters can only be applied to arrays and functions.',
                            'S0401'
                        );
                    }

                    $end = self::findClosingBracket($definition, $position, '<', '>');
                    $parameters[$lastIndex]['subtype'] = substr($definition, $position + 1, $end - $position - 1);
                    $position = $end;
                    break;
            }

            $position++;
        }

        $pattern = '/^'.implode('', array_map(
            fn (array $parameter): string => '('.$parameter['regex'].')',
            $parameters
        )).'$/';

        return new self($definition, $parameters, $pattern);
    }

    public function validate(array $arguments, mixed $context, Evaluator $evaluator): array
    {
        $suppliedSignature = '';
        foreach ($arguments as $argument) {
            $suppliedSignature .= $this->getSymbol($argument, $evaluator);
        }

        if (preg_match($this->pattern, $suppliedSignature, $matches) !== 1) {
            $this->throwValidationError($arguments, $suppliedSignature);
        }

        $validatedArguments = [];
        $argumentIndex = 0;

        foreach ($this->parameters as $index => $parameter) {
            $argument = $arguments[$argumentIndex] ?? null;
            $match = $matches[$index + 1] ?? '';

            if ($match === '') {
                if (($parameter['context'] ?? false) === true) {
                    $contextType = $this->getSymbol($context, $evaluator);

                    if (preg_match($parameter['contextRegex'], $contextType) === 1) {
                        $validatedArguments[] = $context;
                    } else {
                        throw new EvaluationException(
                            sprintf('Error T0411: Context value is not compatible with argument %d.', $argumentIndex + 1),
                            'T0411',
                            0,
                            ['index' => $argumentIndex + 1]
                        );
                    }
                } else {
                    if (array_key_exists($argumentIndex, $arguments)) {
                        $validatedArguments[] = $argument;
                        $argumentIndex++;
                    }
                }

                continue;
            }

            foreach (str_split($match) as $singleSymbol) {
                if (($parameter['type'] ?? null) === 'a') {
                    if ($singleSymbol === 'm') {
                        $validatedArguments[] = $argument;
                        $argumentIndex++;

                        continue;
                    }

                    $argument = $arguments[$argumentIndex] ?? null;
                    $this->ensureArraySubtype($argument, $singleSymbol, $parameter, $argumentIndex + 1, $evaluator);

                    if ($singleSymbol !== 'a') {
                        $argument = [$argument];
                    }

                    $validatedArguments[] = $argument;
                    $argumentIndex++;

                    continue;
                }

                $validatedArguments[] = $argument;
                $argumentIndex++;
            }
        }

        return $validatedArguments;
    }

    public function parameterCount(): int
    {
        return count($this->parameters);
    }

    private static function findClosingBracket(string $input, int $start, string $open, string $close): int
    {
        $depth = 1;
        $position = $start;

        while ($position < strlen($input) - 1) {
            $position++;
            $symbol = $input[$position];

            if ($symbol === $close) {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            } elseif ($symbol === $open) {
                $depth++;
            }
        }

        return $position;
    }

    private function getSymbol(mixed $value, Evaluator $evaluator): string
    {
        if ($evaluator->isMissing($value)) {
            return 'm';
        }

        if ($value instanceof Closure) {
            return 'f';
        }

        return match (true) {
            is_string($value) => 's',
            is_int($value), is_float($value) => 'n',
            is_bool($value) => 'b',
            $value === null => 'l',
            is_array($value) && array_is_list($value) => 'a',
            is_array($value) => 'o',
            default => 'm',
        };
    }

    /**
     * @param  array<string, mixed>  $parameter
     */
    private function ensureArraySubtype(mixed $argument, string $singleSymbol, array $parameter, int $index, Evaluator $evaluator): void
    {
        $subtype = $parameter['subtype'] ?? null;
        if ($subtype === null) {
            return;
        }

        $arrayOk = true;

        if ($singleSymbol !== 'a' && $singleSymbol !== $subtype) {
            $arrayOk = false;
        } elseif ($singleSymbol === 'a' && $argument !== [] && is_array($argument)) {
            $itemType = $this->getSymbol($argument[0], $evaluator);
            if ($itemType !== $subtype[0]) {
                $arrayOk = false;
            } else {
                foreach ($argument as $value) {
                    if ($this->getSymbol($value, $evaluator) !== $itemType) {
                        $arrayOk = false;
                        break;
                    }
                }
            }
        }

        if ($arrayOk) {
            return;
        }

        $typeName = match ($subtype) {
            'a' => 'arrays',
            'b' => 'booleans',
            'f' => 'functions',
            'n' => 'numbers',
            'o' => 'objects',
            's' => 'strings',
            default => $subtype,
        };

        throw new EvaluationException(
            sprintf('Error T0412: Argument %d must be an array of %s.', $index, $typeName),
            'T0412',
            0,
            ['index' => $index, 'type' => $typeName]
        );
    }

    private function throwValidationError(array $arguments, string $signature): never
    {
        $partialPattern = '/^';
        $goodTo = 0;

        foreach ($this->parameters as $parameter) {
            $partialPattern .= $parameter['regex'];
            if (preg_match($partialPattern.'/', $signature, $match) !== 1) {
                throw new EvaluationException(
                    sprintf('Error T0410: Argument %d does not match function signature %s.', $goodTo + 1, $this->definition),
                    'T0410',
                    0,
                    ['index' => $goodTo + 1, 'value' => $arguments[$goodTo] ?? null]
                );
            }

            $goodTo = strlen($match[0]);
        }

        throw new EvaluationException(
            sprintf('Error T0410: Argument %d does not match function signature %s.', $goodTo + 1, $this->definition),
            'T0410',
            0,
            ['index' => $goodTo + 1, 'value' => $arguments[$goodTo] ?? null]
        );
    }
}
