<?php

namespace JsonataPhp;

use RuntimeException;

class EvaluationException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        string $message,
        public readonly string $jsonataCode = 'S0500',
        public readonly int $position = 0,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
