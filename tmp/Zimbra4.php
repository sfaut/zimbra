<?php

namespace sfaut\Zimbra;

class Mailbox
{
    protected $context; // Stream resource
    protected string $session; // Session token
    protected string $url;
    protected string $host;
    protected string $user;
    protected string $password;

    public function __construct(string $host, string $user, string $password)
    {
        $this->url = "{$host}/service/soap";
        $this->host = $host;
        $this->user = $user;
        $this->password = $password;

        $this->setup();
        $this->authenticate();
    }

    protected function setup()
    {
        $this->context = stream_context_create([
            'http' => [
                'method' => 'POST',
                //'header' => ['Content-Type: application/soap+xml'],
                'header' => ['Content-Type: application/json'],
                'content' => '',
                'ignore_errors' => true,
            ],
        ]);
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

        // Add session token to next requests
        $options = stream_context_get_options($this->context);
        $options['http']['header'][] = "Cookie: ZM_AUTH_TOKEN={$this->session}";
        stream_context_set_option($this->context, 'http', 'header', $options['http']['header']);
    }

    /**
     * https://wiki.zimbra.com/wiki/Zimbra_Web_Client_Search_Tips
     * Parameters :
     * in => folder path
     * under => specifies searching a folder and its sub-folders
     * has => specifies an attribute that the message must have, the types of object you can specify are "attachment", "phone", or "url", for example, has:attachment would find all messages which contain one or more attachments of any type
     * filename => specifies an attachment file name, for example filename:query.txt would find messages with a file attachment named "query.txt"
     * subject => message subject
     * from => sender address/name
     * to => recipient address/name
     * toccme => Same as "from:" except that it specifies me as one of the people to whom the email was addressed in the TO: or cc: header
     * cc => ...
     * content => message content
     * not in|subject|from|to => ...
     * term, ...
     * date => ">=-3days" / "yyyy-mm-dd"
     * after => ...
     * before => ...
     * is => read|unrad|...
     */
    public function search(array $parameters, int $limit = 600, int $offset = 0): array
    {
        $query = '';
        foreach ($parameters as $key => $value) {
            $value = '"' . str_replace('"', '""', $value) . '"';
            if (is_int($key)) {
                $query .= "{$value} ";
            } else {
                $query .= "{$key}:{$value} ";
            }
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
                    'limit' => $limit,
                    'offset' => $offset,
//                    'prefetch' => 1,
                    'query' => ['_content' => $query],
                    'locale' => ['_content' => 'fr_CA'], // For date format yyyy-mm-dd
                ],
            ],
        ];

        $request = json_encode($request);
        stream_context_set_option($this->context, 'http', 'content', $request);
        $response = file_get_contents($this->url, false, $this->context);
        $response = json_decode($response);
        $messages = $response->Body->SearchResponse->m ?? [];
        $messages = array_reverse($messages); // Olders first

        $result = [];
        foreach ($messages as $message) {
            $message = new namespace\Message($message);
            $message->mailbox = $this;
            $result[] = $message;
        }

        return $result;
    }

    public function getMessage(): namespace\Message
    {

    }
}

class Message
{
    public \sfaut\Zimbra\Mailbox $mailbox;
    public string $id;
    public string $folder;
    public string $conversation;
    public string $timestamp;       // Y-m-d H:i:s
    public string $subject;
    public array $addresses;        // [{ address, name, type }, ...]
    public array $to;               // [{ address, name, type }, ...]
    public ?\sfaut\Zimbra\Address $from;
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
            $address = new namespace\Address($address);
            $addresses[] = $address;
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
        //$this->attachments = $this->searchAttachments($message->mp);
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
                $body = $this->searchBody($part->mp);
                if ($body !== null) {
                    return $body;
                }
            }
        }
        return null;
    }
}

class Address
{
    // Adresseses mail types
    // (f)rom, (t)o, (c)c, (b)cc, (r)eply-to, (s)ender, read-receipt (n)otification, (rf) resent-from
    protected const types = [
        'f' => 'from',
        't' => 'to',
        'c' => 'cc',
        'b' => 'bcc',
        'r' => 'reply-to',
        's' => 'sender, read-receipt',
        'n' => 'read-receipt notification',
        'rf' => 'resent-from',
    ];

    public string $address;
    public string $name;
    public string $type;

    public function __construct(\stdClass $address)
    {
        $this->address = $address->a;
        $this->name = $address->d;
        $this->type = self::types[$address->t] ?? '(unknow-type)';
    }
}

class Attachment
{

}
