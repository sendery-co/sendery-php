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

    public function prepare(string $to, string $template, array $data, ?string $locale = null, ?string $idempotencyKey = null, array $attachments = []): PendingEmail
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

        return new PendingEmail($this, array_filter(['to' => $to, 'template' => $template, 'data' => (object) $data, 'locale' => $locale, 'attachments' => $attachments ?: null], fn ($value) => $value !== null), $idempotencyKey ?? bin2hex(random_bytes(16)));
    }

    public function send(string $to, string $template, array $data, ?string $locale = null, ?string $idempotencyKey = null, array $attachments = []): array
    {
        return $this->prepare($to, $template, $data, $locale, $idempotencyKey, $attachments)->send();
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
        if (! is_array($decoded) || ! is_string($decoded['id'] ?? null) || ! is_string($decoded['status'] ?? null)) {
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
