# sfaut\Zimbra

Read and send simply Zimbra messages. Attachments are managed.

Based on Zimbra 8 SOAP API.

https://files.zimbra.com/docs/soap_api/8.8.15/api-reference/index.html

## Philosophy

- KISS – Fire & Forget

## Connection

`Zimbra::authenticate()` creates a new `sfaut\Zimbra` instance and immediately connects to Zimbra server.

```php
<?php

use sfaut\Zimbra;

require_once '/path/to/Zimbra.php';

$host = 'https://zimbra.example.net';
$user = 'root@example.net';
$password = 'M;P455w0r|)';

$zimbra = Zimbra::authenticate($host, $user, $password);
```

> To shorten following examples, `use`, `require` and authentication will be snipped. I assume `sfaut\Zimbra` is instanciate in `$zimbra`.

## Error management

An exception is raised when authentication or an other `sfaut\Zimbra` statement failed. So, you should encapsulate statements with `try/catch/finally` blocks.

```php
try {
    $zimbra = Zimbra::authenticate($host, $user, $password);
    // ...
} catch (Exception $e) {
    echo $e->getMessage();
    exit(1);
}
```

> To shorten following examples, exceptions management will be snipped.

## Get mailbox messages

```php
$folder = '/Inbox/My folder';
$messages = $zimbra->getMessages($folder);
foreach ($messages as $i => $message) {
    printf(
        "%6d %s %40s %s\r\n",
        $message->id, $message->timestamp,
        $message->from[0], $message->subject,
    );
}
```

## Send a message

```php
$addresses = ['to' => 'user@exemple.net'];
$subject = 'Hello!';
$body = <<<BUFFER
    Dear Sir,\n
    URGENT BUSINESS PROPOSAL\n
    ...
    BUFFER;
$zimbra->send($addresses, $subject, $body);
```

## Send a message with multiples recipients

You can use arrays to specify multiple e-mail addresses :

```php
// 1 mail to 4 recipients
// Response to trash
$addresses = [
    'to' => ['user1@exemple.net', 'user2@exemple.net'],
    'cc' => 'ml@exemple.net',
    'bcc' => 'archive@exemple.net',
    'r' => 'trash@exemple.net',
];

$zimbra->send($addresses, $subject, $body);
```

## Send message with attachment

Declarative way to add attachments :

```php
$attachments = [
    ['basename' => 'data.csv', 'file' => '/path/to/data.csv'],
];

$zimbra->send($addresses, $subject, $body, $attachments);
```

You can too upload different types of data sources :
- `file` : the value represents the fullpath to the file to attach
- `buffer` : the value represents the raw data to attach
- `stream` : the value is a stream resource to attach
- Attachment ID : a string given when uploading a file previously

```php
$buffer = 'Contents that will be attached to a file';

$stream = fopen('/path/to/file.csv', 'r');

$attachments = [
    ['basename' => 'data-1.csv', 'file' => '/path/to/data.csv'],
    ['basename' => 'data-2.txt', 'buffer' => $buffer],
    ['basename' => 'data-3.csv', 'stream' => $stream],
];

$zimbra->send($addresses, $subject, $body, $attachments);
```