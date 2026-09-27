<?php

namespace Sendery;

class ApiException extends \RuntimeException
{
    public function __construct(public readonly int $status, public readonly string $errorCode, public readonly array $errors = [], public readonly ?float $retryAfter = null)
    {
        parent::__construct('Sendery API error: '.$errorCode, $status);
    }

    public function retryable(): bool
    {
        return in_array($this->status, [0, 500, 502, 503, 504], true) || ($this->status === 429 && $this->errorCode === 'rate_limited');
    }
}
