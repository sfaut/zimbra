<?php

// Comment représenter SOAP avec JSON, selon Zimbra...
// https://wiki.zimbra.com/wiki/Json_format_to_represent_soap

/*
    __construct($host, $user, $password)
    search($parameters)
    getFolderMessages($folder, $count)



*/

namespace sfaut\Zimbra;

class Attachment
{
    public \sfaut\Zimbra\Message $message;
    public string $part;
    public string $type;
    public int $size;
    public string $filename;
    public string $content;

    public function __construct(\sfaut\Zimbra\Message $message, \stdClass $attachment)
    {
        $this->message = $message;
        $this->part = $attachment->part;
        $this->type = $attachment->ct;
        $this->size = $attachment->s;
        $this->filename = $attachment->filename;
        $this->content = null;
    }

    public function getContents()
    {
        $this->content = 'content looking up';
    }
}

class Message
{
    public string $id;
    public string $folder;
    public string $conversation;
    public string $timestamp;       // Y-m-d H:i:s
    public string $subject;
    public array $addresses;        // [{ address, name, type }, ...]
    public array $to;               // [{ address, name, type }, ...]
    public ?\stdClass $from;        // { address, name, type }
    public string $fragment;
    public ?\stdClass $body;        // { part, type, size, content }
    public string $flags;
    public int $size;
    public int $revision;
    public array $attachments;

    public function __construct(\stdClass $message)
    {
        // Parcours des adresses e-mails
        $addresses = [];
        foreach ($message->e as $address) {
            $addresses[] = (object)[
                'address' => $address->a,
                'name' => $address->d,
                'type' => $this->types[$address->t] ?? null,
            ];
        }
        $this->id = $message->id;
        $this->folder = $message->l;
        $this->conversation = $message->cid;
        $this->timestamp = date('Y-m-d H:i:s', $message->d / 1_000); // ms to s
        $this->subject = $message->su;
        $this->addresses = $addresses;
        $this->to = array_values(array_filter($addresses, fn($address) => $address->type === 'to'));
        $this->from = array_filter($addresses, fn($address) => $address->type === 'from')[0];
        $this->fragment = $message->fr ?? '';
        $this->body = $this->searchBody($message->mp);
        $this->flags = $message->f ?? '';
        $this->size = $message->s;
        $this->revision = $message->rev;
        $this->attachments = $this->searchAttachments($message->mp);
    }

    public function getAttachments()
    {

    }

    protected function searchBody(array $parts): ?\stdClass
    {
        foreach ($parts as $part) {
            if ($part->body ?? null === 1) {
                return (object)[
                    'part' => $part->part,
                    'type' => $part->ct,
                    'size' => $part->s,
                    'content' => $part->content,
                ];
            }
            if (isset($part->mp)) {
                return $this->searchBody($part->mp);
            }
        }
        return null;
    }

    protected function searchAttachments(array $parts): array
    {
        $attachments = [];
        foreach ($parts as $part) {
            if (in_array(($part->cd ?? null), ['inline', 'attachment'])) {
                $attachments[] = (object)[
                    'part' => $part->part,
                    'type' => $part->ct,
                    'size' => $part->s,
                    'filename' => $part->filename,
                ];
            } elseif (isset($part->mp)) {
                $attachments = array_merge(
                    $attachments,
                    $this->searchAttachments($part->mp)
                );
            }
        }
        return $attachments;
    }
}

namespace sfaut;

class Zimbra
{
    protected string $url;         // Ex. "https://zimbra.example.net/service/soap"
    protected string $host;        // Ex. "https://zimbra.example.net"
    protected string $user;        // Ex. "user@examples.net"
    protected string $password;    // Ex. "sup4_pa55w0rd"
    protected string $session;
    protected $context;
    protected $messages;

    protected $flags = [
        'u' => 'Unread',
        'f' => 'Flagged',
        'a' => 'Has attachment',
        'r' => 'Replied',
        's' => 'Sent by me',
        'w' => 'Forwarded',
        'v' => 'Calendar invite',
        'd' => 'Draft',
        'x' => 'IMAP-\Deleted',
        'n' => 'Notification sent',
        '!' => 'Urgent',
        '?' => 'Low-priority',
        '+' => 'Priority',
    ];

    // Types d'adresses :
    // (f)rom, (t)o, (c)c, (b)cc, (r)eply-to, (s)ender, read-receipt (n)otification, (rf) resent-from
    protected $types = [
        'f' => 'from',
        't' => 'to',
        'c' => 'cc',
        'b' => 'bcc',
        'r' => 'reply-to',
        's' => 'sender, read-receipt',
        'n' => 'notification',
        'rf' => 'resent-from',
    ];

    public function __construct(string $host, string $user, string $password)
    {
        $this->url = "{$host}/service/soap";
        $this->host = $host;
        $this->user = $user;
        $this->password = $password;
        $this->messages = [];

        $this->createContext();
        $this->authenticate();
    }

    /**
     * Search criterias :
     * in:folder
     * subject:...
     * others ?
     */
    public function search(array $parameters, int $count = 200): array
    {
        $query = '';
        foreach ($parameters as $name => $value) {
            $query .= "{$name}:{$value} ";
        }
        $query = substr($query, 0, -1);

        $request = [
            'Body' => [
                // Requête SearchDirectory => Zimbra 9
                // Zimbra 8 => https://files.zimbra.com/docs/soap_api/8.8.8/api-reference/index.html
                'SearchRequest' => [
                    '_jsns' => 'urn:zimbraMail',
                    'types' => 'message',
                    'sortBy' => 'dateDesc',
                    'fetch' => 'all',
                    'limit' => $count, // Last messages
//                    'prefetch' => 1,
                    'query' => ['_content' => $query],
                ],
            ],
        ];

        $request = json_encode($request);
        stream_context_set_option($this->context, 'http', 'content', $request);
        $response = file_get_contents($this->url, false, $this->context);
        $response = json_decode($response);
        $messages = $response->Body->SearchResponse->m ?? [];
        $messages = array_reverse($messages); // Older first

        $this->messages = [];
        foreach ($messages as $i => $message) { // Beautify message
            $messages[$i] = $this->transform($message);
            $this->messages[] = new \sfaut\Zimbra\Message($message);
        }

        return $messages;
    }

    public function getFolderMessages(string $folderPath, int $count = 200): array
    {
        return $this->search(['in' => $folderPath], $count);
    }

    public function getMessage(string $id, $withAttachments = false): ?stdClass
    {

    }

    public function getAttachment(string $message, string $part): ?string
    {
        $request = [
            'Body' => [
                'GetMsgRequest' => [
                    '_jsns' => 'urn:zimbraMail',
                    'm' => [
                        'id' => $message,
                        'part' => $part,
                        //'raw' => 1,
                        //'useContentUrl' => 1,
                    ],
                ],
            ],
        ];

        $request = json_encode($request);
        stream_context_set_option($this->context, 'http', 'content', $request);
        $response = file_get_contents($this->url, false, $this->context);
        $response = json_decode($response);

        return $response->Body->GetMsgResponse->m[0]->mp[0]->content ?? null;
    }

    public function getFullMessage(string $id): ?stdClass
    {

    }

    public function send(array $addresses, string $contents, array $attachments = [], array $headers = [])
    {

    }

    protected function createContext()
    {
        $this->context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => ['Content-Type: application/soap+xml'],
                'content' => '',
                'ignore_errors' => true,
            ],
        ]);
    }

    protected function transform(\stdClass $message): \stdClass
    {
        // Parcours des adresses e-mails
        $addresses = [];
        foreach ($message->e as $address) {
            $addresses[] = (object)[
                'address' => $address->a,
                'name' => $address->d,
                'type' => $this->types[$address->t] ?? null,
            ];
        }
        return (object)[
            'id' => $message->id,
            'folder' => $message->l,
            'conversation' => $message->cid,
            'timestamp' => date('Y-m-d H:i:s', $message->d / 1_000), // ms to s
            'subject' => $message->su,
            'addresses' => $addresses,
            'to' => array_values(array_filter($addresses, fn($address) => $address->type === 'to')),
            'from' => array_filter($addresses, fn($address) => $address->type === 'from')[0],
            'fragment' => $message->fr ?? '',
            'body' => $this->searchBody($message->mp),
            'flags' => $message->f ?? '',
            'size' => $message->s,
            'revision' => $message->rev,
            'attachments' => $this->searchAttachments($message->mp),
            //'raw' => $message,
        ];
    }

    protected function searchBody(array $parts): ?\stdClass
    {
        foreach ($parts as $part) {
            if ($part->body ?? null === 1) {
                return (object)[
                    'part' => $part->part,
                    'type' => $part->ct,
                    'size' => $part->s,
                    'content' => $part->content,
                ];
            }
            if (isset($part->mp)) {
                return $this->searchBody($part->mp);
            }
        }
        return null;
    }

    protected function searchAttachments(array $parts): array
    {
        $attachments = [];
        foreach ($parts as $part) {
            if (in_array(($part->cd ?? null), ['inline', 'attachment'])) {
                $attachments[] = (object)[
                    'part' => $part->part,
                    'type' => $part->ct,
                    'size' => $part->s,
                    'filename' => $part->filename,
                ];
            } elseif (isset($part->mp)) {
                $attachments = array_merge(
                    $attachments,
                    $this->searchAttachments($part->mp)
                );
            }
        }
        return $attachments;
    }

    protected function authenticate()
    {
        $request = [
            'Body' => [
                'AuthRequest' => [
                    '_jsns' => 'urn:zimbraAccount',
                    'account' => ['by' => 'name', '_content' => $this->user],
                    'password' => ['_content' => $this->password],
                ],
            ],
        ];
        $request = json_encode($request);
        stream_context_set_option($this->context, 'http', 'content', $request);

        $response = file_get_contents($this->url, false, $this->context);
        $response = json_decode($response);

        if (isset($response->Body->Fault)) {
            throw new \Exception('Authentication failed');
        }

        if (!isset($response->Body->AuthResponse->authToken[0]->_content)) {
            throw new \Exception('Empty session token');
        }

        $this->session = $response->Body->AuthResponse->authToken[0]->_content;

        $options = stream_context_get_options($this->context);
        $options['http']['header'][] = "Cookie: ZM_AUTH_TOKEN={$this->session}";
        stream_context_set_option($this->context, 'http', 'header', $options['http']['header']);
    }
}
