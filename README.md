# sfaut\Zimbra

Read and send simply Zimbra messages. Attachments are managed.

Based on Zimbra 8 SOAP API.

https://files.zimbra.com/docs/soap_api/8.8.15/api-reference/index.html

## Introduction

- E-mail messages are versatile. `sfaut\Zimbra` is a relatively low-level class whose aim is to provide a simple anonymous object representing a message and its main components. `sfaut\Zimbra` also provides some helper methods to send and get messages, upload and download attachments, explore directories structure, and make a search.
- KISS – Fire & Forget

## Object structure

Here is a search response structure with an array of messages. A message is an anonymous object.

```
[
    {
        "id": <Message ID, useful for internal usage like attachment download>
        "mid": <Another message ID, useful for querying a specific message>
        "folder": <Folder ID>
        "conversation": <Conversation ID>
        "timestamp": <Message creation, format "Y-m-d H:i:s">
        "subject": <Message subject>
        "addresses": {
            "to": [...]
            "from": [...]
            "cc": [...]
        }
        "fragment": <Fragment of the message>
        "flags": <Message flags>
        "size": <Message size, in bytes>
        "body": <Message body>
        "attachments": [
            {
                "part": <Attachment's part message>
                "disposition": <MIME disposition, "inline" or "attachment">
                "type": <MIME type, ex. "text/csv">
                "size": <Attachment size, in bytes>
                "basename": <Attachment basename (with extension), ex. "Report.csv">
                "filename": <Attachment filename (without extension), ex. "Report">
                "extension": <Attachment extension without dot, ex. "csv">
            }
            ...
        ]
    }
    ...
]
```

## Connection

Static method `Zimbra::authenticate()` creates a new `sfaut\Zimbra` instance and immediately connects to Zimbra server.

```php
<?php

use sfaut\Zimbra;

require_once '/path/to/Zimbra.php';

$host = 'https://zimbra.example.net';
$user = 'root@example.net';
$password = 'M;P455w0r|)';

$zimbra = Zimbra::authenticate($host, $user, $password);
```

> To shorten following examples, `use`, `require` and authentication will be snipped. Assume `sfaut\Zimbra` is instanciate in `$zimbra`.

## Error management

An exception is raised when authentication or an other `sfaut\Zimbra` statement failed.
So, you should encapsulate statements within `try / catch / finally` blocks.

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

All messages accesses are, in reality, search result.

```php
$folder = '/Inbox';
$messages = $zimbra->search(['in' => $folder]);
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

Basically, an **attachment** is an anonymous object (or an array) containing 2 properties :
- `basename` : the name + extension of the attached file
- A type `buffer`, `file` or `stream` and its related value

Description details :
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

You can also attach multiple files in a row :

```php
$attachments = [
    ['basename' => 'data-1.csv', 'file' => '/path/to/data-1.csv'],
    ['basename' => 'data-2.csv', 'file' => '/path/to/data-2.csv'],
    ['basename' => 'data-3.csv', 'file' => '/path/to/data-3.csv'],
];

$zimbra->send($addresses, $subject, $body, $attachments);
```

And you can mix different types of data sources :

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
That can be unnecessarily resource-consuming if you send multiple messages with the same attachments.
To save resources, you can first upload files with `Zimbra::upload()`, then attach them to messages.

```php
// ⚠️ YOU SHOULD NOT DO THIS
// The same file uploaded 3 times for 3 messages
$attachments = [
    ['basename' => 'decennial-data.csv', 'file' => '/path/to/decennial-data.csv'],
    ['basename' => 'another-data.csv', 'file' => '/path/to/another-data.csv'],
];
$zimbra->send($addresses_1, $subject, $body, $attachments); // 🙅🏻‍♂️
$zimbra->send($addresses_2, $subject, $body, $attachments); // 🙅🏻‍♂️
$zimbra->send($addresses_3, $subject, $body, $attachments); // 🙅🏻‍♂️

// 💡 YOU SHOULD DO THAT
// 1 upload for 3 messages
$attachments = [
    ['basename' => 'decennial-data.csv', 'file' => '/path/to/decennial-data.csv'],
    ['basename' => 'another-data.csv', 'file' => '/path/to/another-data.csv'],
];

// 🥂 That's the trick
// An attachment ID is retrieved and reused as necessary
$attachments = $zimbra->upload($attachments);

$zimbra->send($addresses_1, $subject, $body, $attachments);
$zimbra->send($addresses_2, $subject, $body, $attachments);
$zimbra->send($addresses_3, $subject, $body, $attachments);
```

## Attachments

Message attachments are specified in array `$message->attachments`.

Attachments can be uploaded with `Zimbra::upload()` and downloaded with `Zimbra::download()`.

Each attachment is an anonymous object having the following structure :

```php
{
    "part": <MIME part of the attachment in the message, eg. "2" or "2.1.1">
    "disposition": <Attachment method to the message : "attachment" or "inline">
    "type": <MIME type of the attachment file, eg. "text/plain", "text/csv">
    "size": <Attachement file size in bytes>
    "basename": <Attachement file name with extension, eg. "my-data.csv">
    "filename": <Attachment file name without extension, eg. "my-data">
    "extension": <Attachment extension without dot, eg. "csv">
    "stream": <Stream from temporary, only after Zimbra::download() call>
}
```

## Attachments retrieving

Attachments are retrieved with `Zimbra::download()` and parameters `$message->id`
and part `$message->attachments[*]->part` to download.

You want to retrieve the unique file of the mail *Annual result 2022* and save it under its original name :

```php
$message = $zimbra->search(['subject' => 'Annual result 2022'])[0];
$attachment = $zimbra->download($message)[0];

// Where you want to save your file
$destination_file = "/path/to/{$attachment->basename}";

// 1st method, with stream
// Memory efficient
$destination_stream = fopen($destination_file, 'w');
stream_copy_to_stream($attachment->stream, $destination_stream);

// 2nd method, with buffer
// Memory inefficient on huge files, but you can process $buffer
$buffer = stream_get_contents($attachment->stream);
file_put_contents($destination_file, $buffer);
```

## Real-life use case

### Mass download attachments

- You need to download tons of messages CSV attachments, deadline : yesterday
- Messages are stored on mailbox in folder `Inbox/Reports`
- Each message has 0 to n attachments
- Attachments can be of any types like `.csv`, `.xlsx`, `.pdf`, etc., and you need to retrieve only `.csv`
- CSV files are named in the following format : `Report Y-m-d.csv`, ex. `Report 2022-03-06.csv`
- Filename, and its extension, can be in lower or upper case, or mix, you need to manage that
- You must download all CSV attachments starting `2020-01-01`, ex. `Report 2019-12-31.csv` is not downloaded whereas `Report 2020-01-01.csv` is downloaded
- There are a lot of files, so you must save them as Gzip files
- Each message subject is unique, but each attachment name is not, so attachments downloaded must have a name in format `Message subject -- Attachment basename.gz`, ex. `Report 2020-01-01.csv.gz`
- Target directory is the local subdirectory `/mailbox/reports`

PHP and `sfaut\Zimbra` allows you to do that easily :)

```php
// Starting attachment, you choose an all upper case reference
$starting_file = 'REPORT 2020-01-01.CSV';

// Mailbox source folder
$source_folder = '/Inbox/Reports';

// Locale target subdirectory, where all CSV attachments will be downloaded
$target_directory = __DIR__ . '/mailbox/reports';

// Search messages in source folder that have at least one CSV attachment begining with "Report"
// You reduce unuseful messages retrieving
$messages = $zimbra->search(['in' = $source_folder, 'filename' => 'Report*', 'type' => 'text/csv']);

foreach ($messages as $message) {
    // Download attachments, only what you need
    $attachments = $zimbra->download($message, function ($attachment) {
        if (strtoupper($attachment->extension) !== 'CSV') {
            return false;
        }
        if (strtoupper($attachment->basename) < $starting_file) {
            return false;
        }
        return true;
    });
    // Save CSV attachments in safe place
    foreach ($attachments as $attachment) {
        $target_file = "{$target_directory}/{$message->subject} -- {$attachment->basename}.gz";
        $target_stream = gzopen($target_file, 'w');
        stream_copy_to_stream($attachment->stream, $target_stream);
    }
}
```

That's all Folks! 🐰
