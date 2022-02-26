# Zimbra

Read and send Zimbra messages. Attachments are managed.

Based on Zimbra 8 SOAP API.

## Philosophy

- KISS – Fire & Forget

## Example -- Connection

```
<?php

use sfaut\Zimbra;

require_once '/path/to/Zimbra.php';

$host = 'https://zimbra.example.net';
$user = 'root@example.net';
$password = 'M;P455w0r|)';

$zimbra = Zimbra::authenticate($host, $user, $password);
```

> To shorten following examples, `use`, `require` and authentication will be snipped. I assume `sfaut\Zimbra` is instanciate in `$zimbra`.

## Example -- Error management

An exception is raised when authentication or an other `sfaut\Zimbra` statement failed. So, you should encapsulate statements with `try/catch/finally` blocks.

```
try {
    $zimbra = Zimbra::authenticate($host, $user, $password);
    // ...
} catch (Exception $e) {
    echo $e->getMessage();
    exit(1);
}
```

## Example -- Get mailbox messages

```
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
