<?php

use PHPUnit\Framework\TestCase;
use Sendery\ApiException;
use Sendery\Attachment;
use Sendery\Client;

class ClientTest extends TestCase
{
    public function test_render_uses_a_separate_endpoint_and_does_not_send_a_recipient_or_attachments(): void
    {
        $client = new Client('snd_sandbox_secret', 'https://example.com', function ($method, $url, $headers, $body): array {
            $this->assertSame('POST', $method);
            $this->assertSame('https://example.com/api/v1/emails/render', $url);
            $this->assertSame('Bearer snd_sandbox_secret', $headers['Authorization']);
            $this->assertSame(['template' => 'welcome', 'data' => ['name' => 'Steven'], 'locale' => 'ja', 'version' => 2], json_decode($body, true));
            $this->assertArrayNotHasKey('Idempotency-Key', $headers);

            return ['status' => 200, 'body' => '{"subject":"Hello","html":"<p>Hello</p>","text":"Hello"}'];
        });
        $this->assertSame('Hello', $client->render('welcome', ['name' => 'Steven'], 'ja', 2)['subject']);
    }

    public function test_render_rejects_malformed_responses_and_propagates_capacity_errors(): void
    {
        foreach ([['status' => 200, 'body' => '{"html":"body"}'], ['status' => 429, 'body' => '{"code":"render_limit_exceeded"}']] as $response) {
            $client = new Client('key', 'https://example.com', fn () => $response);
            try {
                $client->render('welcome', []);
                $this->fail('Expected render error.');
            } catch (ApiException $error) {
                $this->assertSame($response['status'] === 200 ? 'invalid_response' : 'render_limit_exceeded', $error->errorCode);
            }
        }
    }

    public function test_retries_preserve_payload_and_key_and_retrieve_status(): void
    {
        $calls = [];
        $client = new Client('secret', 'https://example.com', function ($method, $url, $headers, $body) use (&$calls): array {
            $calls[] = compact('method', 'url', 'headers', 'body');

            return count($calls) === 1 ? ['status' => 503, 'body' => '{}', 'retry_after' => '0'] : ['status' => 202, 'body' => '{"id":"msg","status":"queued"}'];
        });
        $data = ['name' => 'Original'];
        $email = $client->prepare('steven@example.com', 'welcome', $data, attachments: [new Attachment('invoice.pdf', "PDF\x00bytes", 'application/pdf')]);
        $email->version(3);
        $data['name'] = 'Changed';
        $this->assertSame('msg', $email->retry()->send()['id']);
        $this->assertSame($calls[0], $calls[1]);
        $this->assertSame(3, json_decode($calls[0]['body'], true)['version']);
        $this->assertSame(base64_encode("PDF\x00bytes"), json_decode($calls[1]['body'], true)['attachments'][0]['content']);
        $this->assertStringContainsString('Original', $calls[1]['body']);
        $client->get('msg');
        $this->assertSame('GET', $calls[2]['method']);
    }

    public function test_events_freeze_nested_data_and_share_retry_handling(): void
    {
        $calls = [];
        $client = new Client('secret', 'https://example.com', function ($method, $url, $headers, $body) use (&$calls): array {
            $calls[] = compact('method', 'url', 'headers', 'body');

            return count($calls) === 1 ? ['status' => 503, 'body' => '{}', 'retry_after' => '0'] : ['status' => 202, 'body' => '{"id":"event","status":"accepted","matched_workflows":2}'];
        });
        $data = ['owner' => ['email' => 'steven@example.com']];
        $event = $client->event('listing.created', 'listing', '42', $data, 'attempt-1');
        $data['owner']['email'] = 'changed@example.com';
        $this->assertSame(2, $event->retry()->send()['matched_workflows']);
        $this->assertSame($calls[0], $calls[1]);
        $this->assertSame('https://example.com/api/v1/events', $calls[0]['url']);
        $this->assertSame('steven@example.com', json_decode($calls[0]['body'], true)['data']['owner']['email']);
        $client->getEvent('event/id');
        $this->assertSame('https://example.com/api/v1/events/event%2Fid', $calls[2]['url']);
        $client->forgetObject('listing', '42');
        $this->assertSame('DELETE', $calls[3]['method']);
    }

    public function test_version_is_optional_and_requires_a_positive_number(): void
    {
        $client = new Client('secret', 'https://example.com', function ($method, $url, $headers, $body): array {
            $this->assertArrayNotHasKey('version', json_decode($body, true));

            return ['status' => 202, 'body' => '{"id":"msg","status":"queued"}'];
        });
        $client->send('steven@example.com', 'welcome', []);
        $this->expectException(InvalidArgumentException::class);
        $client->prepare('steven@example.com', 'welcome', [])->version(0);
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

    public function test_event_definitions_have_separate_response_validation(): void
    {
        $client = new Client('test', 'https://sendery.co', function ($method, $url, $headers, $body): array {
            $this->assertArrayNotHasKey('Idempotency-Key', $headers);
            if ($method === 'PUT') {
                $this->assertSame('https://sendery.co/api/v1/event-types/document.signed', $url);
                $this->assertSame('Document signed', json_decode($body, true)['label']);

                return ['status' => 200, 'body' => '{"name":"document.signed","verified":false}'];
            }

            return ['status' => 200, 'body' => '{"events":[]}'];
        });
        $this->assertFalse($client->defineEvent('document.signed', ['label' => 'Document signed', 'object' => ['type' => 'document', 'label' => 'Document']])['verified']);
        $this->assertSame([], $client->getEventTypes());
        $this->expectException(ApiException::class);
        $client->get('email');
    }
}
