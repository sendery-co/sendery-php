<?php

namespace Sendery;

class PendingEmail extends PendingRequest
{
    public function __construct(Client $client, array $payload, string $idempotencyKey)
    {
        parent::__construct($client, $payload, $idempotencyKey, '/api/v1/emails');
    }

    public function version(int $version): self
    {
        if ($version < 1) {
            throw new \InvalidArgumentException('Version must be a positive integer.');
        }
        $payload = json_decode($this->body, flags: JSON_THROW_ON_ERROR);
        $payload->version = $version;
        $this->body = json_encode($payload, JSON_THROW_ON_ERROR);

        return $this;
    }
}
