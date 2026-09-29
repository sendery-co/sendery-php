<?php

use PHPUnit\Framework\TestCase;
use Sendery\ApiException;
use Sendery\Attachment;
use Sendery\Client;

class ClientTest extends TestCase
{
    public function test_retries_preserve_payload_and_key_and_retrieve_status(): void
    {
        $calls = [];
        $client = new Client('secret', 'https://example.com', function ($method, $url, $headers, $body) use (&$calls): array {
            $calls[] = compact('method', 'url', 'headers', 'body');

            return count($calls) === 1 ? ['status' => 503, 'body' => '{}', 'retry_after' => '0'] : ['status' => 202, 'body' => '{"id":"msg","status":"queued"}'];
        });
        $data = ['name' => 'Original'];
        $email = $client->prepare('alex@example.com', 'welcome', $data, attachments: [new Attachment('invoice.pdf', "PDF\x00bytes", 'application/pdf')]);
        $data['name'] = 'Changed';
        $this->assertSame('msg', $email->retry()->send()['id']);
        $this->assertSame($calls[0], $calls[1]);
        $this->assertSame(base64_encode("PDF\x00bytes"), json_decode($calls[1]['body'], true)['attachments'][0]['content']);
        $this->assertStringContainsString('Original', $calls[1]['body']);
        $client->get('msg');
        $this->assertSame('GET', $calls[2]['method']);
    }

    public function test_capacity_errors_are_not_retried(): void
    {
        $count = 0;
        $client = new Client('secret', 'https://example.com', function () use (&$count): array {
            $count++;

            return ['status' => 429, 'body' => '{"code":"email_capacity_exceeded"}'];
        });
        try {
            $client->prepare('a@example.com', 'welcome', [])->retry()->send();
            $this->fail('Expected exception');
        } catch (ApiException $error) {
            $this->assertSame(429, $error->status);
            $this->assertSame(1, $count);
        }
    }

    public function test_redirects_and_invalid_endpoints_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Client('secret', 'http://example.com');
    }

    public function test_explicit_key_and_empty_object_are_preserved(): void
    {
        $client = new Client('secret', 'https://example.com', function ($method, $url, $headers, $body): array {
            $this->assertSame('event-123', $headers['Idempotency-Key']);
            $this->assertStringContainsString('"data":{}', $body);

            return ['status' => 200, 'body' => '{"id":"msg","status":"queued"}'];
        });
        $client->send('a@example.com', 'welcome', [], idempotencyKey: 'event-123');
    }
}
