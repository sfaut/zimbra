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

4th `Zimbra::send()` parameter is an array of **attachments**.

Basically, an **attachment** is an array or an object containing 2 values :
- `basename` : the name + extension of the attached file
- A type `buffer`, `file` or `stream` and its related value

Types description :
- `file` : the value represents the file full path to attach
- `buffer` : the value represents the raw data to attach
- `stream` : the value is the stream resource to attach
- Attachment ID : a string given when uploading a file previously

Attach a file to a message :

```php
$attachments = [
    ['basename' => 'data.csv', 'file' => '/path/to/data.csv'],
];

$zimbra->send($addresses, $subject, $body, $attachments);
```

Wen can attach multiple files in a row :

```php
$attachments = [
    ['basename' => 'data-1.csv', 'file' => '/path/to/data-1.csv'],
    ['basename' => 'data-2.csv', 'file' => '/path/to/data-2.csv'],
    ['basename' => 'data-3.csv', 'file' => '/path/to/data-3.csv'],
];

$zimbra->send($addresses, $subject, $body, $attachments);
```

And we can mix different types of data sources :

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

Eeach attachment is uploaded when sending message.
That can be unnecessarily resource-consuming if we send multiple messages with the same attachments.
To save resources you can first upload files with `Zimbra::uploadAttachment()`, then attach them to messages.

```php
// ⚠️ YOU SHOULD NOT DO THIS
// The same file uploaded 3 times for 3 messages
$attachments = [
    ['basename' => 'decennial-data.csv', 'file' => '/path/to/huge-data.csv'],
];
$zimbra->send($addresses_1, $subject, $body, $attachments);
$zimbra->send($addresses_2, $subject, $body, $attachments);
$zimbra->send($addresses_3, $subject, $body, $attachments);

// 💡 YOU SHOULD DO THAT
// 1 upload for 3 messages
$attachment = ['basename' => 'decennial-data.csv', 'file' => '/path/to/huge-data.csv'];
$aid = $zimbra->uploadAttachment($attachment); // Attachment ID is an internal Zimbra ID
$attachments = [$aid];
$zimbra->send($addresses_1, $subject, $body, $attachments);
$zimbra->send($addresses_2, $subject, $body, $attachments);
$zimbra->send($addresses_3, $subject, $body, $attachments);
```

## Attachments

Message attachments are specified in array `$message->attachments`.

Each attachment is an anonymous object having the following structure :

```php
{
    "part": <MIME part of the attachment in the message, eg. "2" or "2.1.1">,
    "disposition": <Attachment method to the message : "attachment" or "inline">,
    "type": <MIME type of the attachment file, eg. "text/plain", "text/csv">,
    "size": <Attachement file size in bytes>,
    "basename": <Attachement file name with extension, eg. "my-data.csv">
}
```

## Attachments retrieving

Attachments are retrieved with `Zimbra::download()` and parameters `$message->id`
and part `$message->attachments[*]->part` to download.

You want to retrieve the unique file of the mail *Annual result 2022* and save it under its original name :

```php
$message = $zimbra->getSearch(['subject' => 'Annual result 2022'])[0];
$attachment = $message->attachments[0];
$buffer = $zimbra->getAttachment($message->id, $attachment->part);
file_put_contents("/path/to/{$attachment->basename}", $buffer);
```