<?php

namespace Sendery;

class PendingEmail
{
    private string $body;

    private int $retries = 0;

    public function __construct(private Client $client, array $payload, public readonly string $idempotencyKey)
    {
        if (! preg_match('/\A[a-zA-Z0-9_.:-]{1,128}\z/', $idempotencyKey)) {
            throw new \InvalidArgumentException('Invalid idempotency key.');
        }
        $this->body = json_encode($payload, JSON_THROW_ON_ERROR);
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

    public function retry(int $retries = 3): self
    {
        if ($retries < 0 || $retries > 5) {
            throw new \InvalidArgumentException('Choose 0 to 5 retries.');
        }
        $this->retries = $retries;

        return $this;
    }

    public function send(): array
    {
        for ($attempt = 0; ; $attempt++) {
            try {
                return $this->client->request('POST', '/api/v1/emails', $this->body, $this->idempotencyKey);
            } catch (ApiException $error) {
                if ($attempt >= $this->retries || ! $error->retryable()) {
                    throw $error;
                }
                $delay = $error->retryAfter ?? min(8, 0.25 * 2 ** $attempt + random_int(0, 100) / 1000);
                if ($delay > 30) {
                    throw $error;
                }
                usleep((int) ($delay * 1000000));
            }
        }
    }
}
