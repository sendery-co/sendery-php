<?php

namespace Sendery;

class Client
{
    /** @param null|\Closure(string, string, array, ?string): array{status:int, body:string, retry_after?:string} $transport */
    public function __construct(private string $apiKey, private string $url = 'https://sendery.co', private ?\Closure $transport = null)
    {
        $parts = parse_url($url);
        if (! $apiKey || ! $parts || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || ! isset($parts['host']) || (($parts['scheme'] ?? '') !== 'https' && ! (($parts['scheme'] ?? '') === 'http' && in_array($parts['host'], ['localhost', '127.0.0.1', '[::1]'], true)))) {
            throw new \InvalidArgumentException('Provide an API key and an HTTPS URL (HTTP is allowed on loopback only).');
        }
    }

    public function prepare(string $to, string $template, array $data, ?string $locale = null, ?string $idempotencyKey = null, array $attachments = [], ?int $version = null): PendingEmail
    {
        $size = 0;
        foreach ($attachments as $attachment) {
            if (! $attachment instanceof Attachment) {
                throw new \InvalidArgumentException('Use Attachment objects.');
            }
            $size += $attachment->size();
        }
        if (count($attachments) > 10 || $size > 5242880) {
            throw new \InvalidArgumentException('Use at most 10 attachments, up to 5 MB combined.');
        }

        $email = new PendingEmail($this, array_filter(['to' => $to, 'template' => $template, 'data' => (object) $data, 'locale' => $locale, 'attachments' => $attachments ?: null], fn ($value) => $value !== null), $idempotencyKey ?? bin2hex(random_bytes(16)));

        return $version === null ? $email : $email->version($version);
    }

    public function send(string $to, string $template, array $data, ?string $locale = null, ?string $idempotencyKey = null, array $attachments = [], ?int $version = null): array
    {
        return $this->prepare($to, $template, $data, $locale, $idempotencyKey, $attachments, $version)->send();
    }

    /** Render a published template without sending or saving an email. */
    public function render(string $template, array $data, ?string $locale = null, ?int $version = null): array
    {
        if ($version !== null && $version < 1) {
            throw new \InvalidArgumentException('Version must be a positive integer.');
        }

        return $this->request('POST', '/api/v1/emails/render', json_encode(array_filter([
            'template' => $template, 'data' => (object) $data, 'locale' => $locale, 'version' => $version,
        ], fn ($value) => $value !== null), JSON_THROW_ON_ERROR));
    }

    public function defineEvent(string $name, array $definition): array
    {
        $definition['fields'] = (object) ($definition['fields'] ?? []);

        return $this->request('PUT', '/api/v1/event-types/'.rawurlencode($name), json_encode($definition, JSON_THROW_ON_ERROR));
    }

    public function getEventTypes(): array
    {
        return $this->request('GET', '/api/v1/event-types')['events'];
    }

    public function event(string $name, string $objectType, string $objectId, array $data, ?string $correlationId = null, ?string $idempotencyKey = null, ?string $language = null): PendingEvent
    {
        return new PendingEvent($this, array_filter([
            'name' => $name, 'object' => ['type' => $objectType, 'id' => $objectId],
            'data' => (object) $data, 'correlation_id' => $correlationId, 'language' => $language,
        ], fn ($value) => $value !== null), $idempotencyKey ?? bin2hex(random_bytes(16)));
    }

    public function getEvent(string $id): array
    {
        return $this->request('GET', '/api/v1/events/'.rawurlencode($id));
    }

    public function forgetObject(string $type, string $id): array
    {
        return $this->request('DELETE', '/api/v1/event-objects', json_encode(['type' => $type, 'id' => $id], JSON_THROW_ON_ERROR));
    }

    public function get(string $id): array
    {
        return $this->request('GET', '/api/v1/emails/'.rawurlencode($id));
    }

    public function request(string $method, string $path, ?string $body = null, ?string $key = null): array
    {
        $headers = ['Authorization' => 'Bearer '.$this->apiKey, 'Accept' => 'application/json', 'Content-Type' => 'application/json'];
        if ($key !== null) {
            $headers['Idempotency-Key'] = $key;
        }
        $url = rtrim($this->url, '/').$path;
        $result = $this->transport ? ($this->transport)($method, $url, $headers, $body) : $this->http($method, $url, $headers, $body);
        if ($result['status'] < 200 || $result['status'] >= 300) {
            $error = json_decode($result['body'], true);
            $error = is_array($error) ? $error : [];
            $retryAfter = $result['retry_after'] ?? null;
            $delay = $retryAfter === null ? null : (is_numeric($retryAfter) ? max(0, (float) $retryAfter) : max(0, (strtotime($retryAfter) ?: time()) - time()));
            throw new ApiException($result['status'], $error['code'] ?? 'request_error', $error['errors'] ?? [], $delay);
        }
        try {
            $decoded = json_decode($result['body'], true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiException(0, 'invalid_response');
        }
        $valid = is_array($decoded) && match (true) {
            $path === '/api/v1/emails/render' => is_string($decoded['subject'] ?? null) && is_string($decoded['html'] ?? null) && is_string($decoded['text'] ?? null),
            $path === '/api/v1/event-types' => is_array($decoded['events'] ?? null),
            str_starts_with($path, '/api/v1/event-types/') => is_string($decoded['name'] ?? null) && is_bool($decoded['verified'] ?? null),
            default => is_string($decoded['id'] ?? null) && is_string($decoded['status'] ?? null),
        };
        if (! $valid) {
            throw new ApiException(0, 'invalid_response');
        }

        return $decoded;
    }

    private function http(string $method, string $url, array $headers, ?string $body): array
    {
        $handle = curl_init($url);
        $retryAfter = null;
        curl_setopt_array($handle, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => array_map(fn ($name, $value) => $name.': '.$value, array_keys($headers), $headers),
            CURLOPT_HEADERFUNCTION => function ($handle, string $line) use (&$retryAfter): int {
                if (stripos($line, 'Retry-After:') === 0) {
                    $retryAfter = trim(substr($line, 12));
                }

                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }
        $result = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if ($result === false) {
            throw new ApiException(0, 'connection_error');
        }

        return ['status' => $status, 'body' => $result, 'retry_after' => $retryAfter];
    }
}
