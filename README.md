# Sendery for PHP

Send published Sendery templates from PHP.

[Documentation](https://sendery.co/en/docs/php) · [API reference](https://sendery.co/en/docs/send-email) · [Changelog](CHANGELOG.md)

## Requirements

PHP 8.3+ with the cURL extension.

## Install

```bash
composer require sendery/php:^0.1.1
```

## Set up

Choose a published template and create a [project API key](https://sendery.co/en/docs/authentication). Store the key as `SENDERY_API_KEY` on your server.

```bash
export SENDERY_API_KEY="your_project_api_key"
```

## Send an email

Replace `your-template` with your published template’s key and `data` with its variables.

The response contains the email’s `id` and `status`. If your template has no variables, pass `data: []`.

```php
require 'vendor/autoload.php';

use Sendery\Client;

$sendery = new Client(getenv('SENDERY_API_KEY'));
$receipt = $sendery->send(
    to: 'alex@example.com',
    template: 'your-template',
    data: ['name' => 'Alex', 'action_url' => 'https://example.com/start'],
);

echo $receipt['id'];
```

## Send a specific version

Choose a [published template version](https://sendery.co/en/docs/send-email#section-5) to keep sending it after newer versions are published. By default, Sendery uses the latest version.

```php
$receipt = $sendery->prepare(
    to: 'alex@example.com',
    template: 'your-template',
    data: ['name' => 'Alex', 'action_url' => 'https://example.com/start'],
)->version(3)->send();
```

## Attachments

Pass files through `attachments`. Use `Attachment::fromPath()` for a local file, or `new Attachment()` for file contents you already have.

Send up to 10 files totaling 5 MB. See the [attachment reference](https://sendery.co/en/docs/send-email#section-6) for supported formats and limits.

```php
use Sendery\Attachment;

$sendery->prepare(
    to: 'alex@example.com',
    template: 'your-template',
    data: ['name' => 'Alex', 'action_url' => 'https://example.com/start'],
    idempotencyKey: 'your-idempotency-key',
    attachments: [
        Attachment::fromPath('/path/document.pdf', 'document.pdf', 'application/pdf'),
    ],
)->retry()->send();
```

## Retrieve an email

Use the returned ID to [check delivery status](https://sendery.co/en/docs/get-email).

```php
$message = $sendery->get($receipt['id']);
echo $message['status'];
```

## Retry a send

Use `retry(3)` for up to three extra attempts after temporary failures. Keep the same [idempotency key and email data](https://sendery.co/en/docs/idempotency) on every attempt.

```php
$email = $sendery->prepare(
    to: 'alex@example.com',
    template: 'your-template',
    data: ['name' => 'Alex', 'action_url' => 'https://example.com/start'],
    idempotencyKey: 'your-idempotency-key',
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

Learn how to [retry emails without duplicate sends](https://sendery.co/en/docs/idempotency).

## License

[MIT](LICENSE).
