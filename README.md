# Sendery — PHP integration

Send template emails from PHP with the Sendery SDK.

MIT licensed. Repository: https://github.com/sendery-co/sendery-php

Documentation: https://sendery.co/en/docs/php

## Install

```
composer require sendery/php:^0.1
```

## Install

PHP 8.3+

## Configure your client

Read SENDERY_API_KEY from the server environment. The PHP SDK has no framework dependency and uses cURL with connection and request timeouts.

## Send and retrieve

Use prepare()->retry()->send() for safe bounded retries, or send() for one attempt. Use get($id) for status. Catch Sendery\ApiException and inspect status, errorCode, errors, and retryAfter.

## Example

```
$sendery = new \Sendery\Client(getenv('SENDERY_API_KEY'));
$email = $sendery->prepare(
    to: 'alex@example.com',
    template: 'welcome',
    data: ['name' => 'Alex'],
);
$receipt = $email->retry(3)->send();
```

## Retries and queues

Reuse a prepared email for retries. New requests receive new keys; when reconstructing a request in another process, supply the original key and unchanged data. Keep API keys server-side. Framework mailers send Sendery templates, not arbitrary HTML or attachments.
