<?php

namespace JsonataPhp;

class RegexPattern
{
    public function __construct(
        public readonly string $pattern,
        public readonly string $flags = '',
    ) {}

    public function toPcre(): string
    {
        $escaped = str_replace('/', '\/', $this->pattern);

        return '/'.$escaped.'/'.$this->flags;
    }
}
