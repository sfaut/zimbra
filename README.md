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

$z = Zimbra::authenticate($host, $user, $password);
```

> To shorten following examples, `use`, `require` and authentication will be snipped.

## Example -- Errors management

An exception is raised when authentication, or other `sfaut\Zimbra` statement, failed. So, you should encapsulate statements with `try/catch/finally` blocks.

```
try {
    $z = Zimbra::authenticate($host, $user, $password);
    // ...
} catch (Exception $e) {
    echo $e->getMessage();
    exit(1);
}
```

## Example -- Get messages

```
$folder = 'Inbox/My folder';
$messages = $z->getMessages($folder);
foreach ($messages as $message) {
    // ...
}
```
