<?php

namespace Sendery;

class PendingEvent extends PendingRequest
{
    public function __construct(Client $client, array $payload, string $idempotencyKey)
    {
        parent::__construct($client, $payload, $idempotencyKey, '/api/v1/events');
    }
}
