# Sendery for PHP

Send published Sendery templates from PHP.

[Documentation](https://sendery.co/en/docs/php) · [API reference](https://sendery.co/en/docs/send-email) · [Changelog](CHANGELOG.md)

## Requirements

PHP 8.3+ with the cURL extension.

## Install

```bash
composer require sendery/php:^0.1
```

## Set up

Publish a `welcome` template with `name` and `action_url` variables, and create a [project API key](https://sendery.co/en/docs/authentication). Store it as `SENDERY_API_KEY` on your server.

```bash
export SENDERY_API_KEY="your_project_api_key"
```

## Send an email

The response contains the accepted email’s `id` and `status`. For a template without variables, pass `data: []`; the SDK sends an empty JSON object.

```php
require 'vendor/autoload.php';

use Sendery\Client;

$sendery = new Client(getenv('SENDERY_API_KEY'));
$receipt = $sendery->send(
    to: 'alex@example.com',
    template: 'welcome',
    data: ['name' => 'Alex', 'action_url' => 'https://example.com/start'],
);

echo $receipt['id'];
```

## Retrieve an email

Use the returned ID to [check delivery status](https://sendery.co/en/docs/get-email).

```php
$message = $sendery->get($receipt['id']);
echo $message['status'];
```

## Retry a send

Use a key such as `welcome-123` for one email, and [keep the payload unchanged on retries](https://sendery.co/en/docs/idempotency). `retry(3)` allows up to three additional attempts for temporary failures; `send()` alone makes one attempt.

```php
$email = $sendery->prepare(
    to: 'alex@example.com',
    template: 'welcome',
    data: ['name' => 'Alex', 'action_url' => 'https://example.com/start'],
    idempotencyKey: 'welcome-123',
);
$receipt = $email->retry(3)->send();
```

## Handle errors

Catch the SDK exception to inspect the [status and code](https://sendery.co/en/docs/errors). Retry delays are in seconds. The example uses the prepared `$email` from the [retry example above](#retry-a-send).

```php
try {
    $receipt = $email->retry(3)->send();
} catch (\Sendery\ApiException $error) {
    error_log($error->status.': '.$error->errorCode);
    // $error->errors contains field-level validation errors.
    // $error->retryAfter is a delay in seconds, when provided.
    throw $error;
}
```

## More

See [idempotency and retries](https://sendery.co/en/docs/idempotency) for retry conditions, delays, and reusing a key across attempts.

## License

[MIT](LICENSE).
