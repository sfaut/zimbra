<?php

namespace sfaut;

class Zimbra
{
    protected string $host;     // Scheme and host
    protected string $email;    // User account
    protected string $soap;     // SOAP base URL
    protected string $upload;   // Upload base URL
    protected ?string $session; // Session token
    protected $context;         // Base stream context

    // Internal Zimbra email addresses types
    protected array $types = [
        'f' => 'from',
        't' => 'to',
        'c' => 'cc',
        'b' => 'bcc',
        'r' => 'reply-to',
        's' => 'sender, read-receipt',
        'n' => 'notification',
        'rf' => 'resent-from',
    ];

    /**
     * $host : ex. "https://webmail.free.fr", no trailing slash needed
     * $email : ex. "my-email", or "my-email@free.fr"
     * $password : your password
     */
    protected function __construct(string $host, string $email, string $password)
    {
        $this->host = $host;
        $this->email = $email;
        $this->soap = "{$this->host}/service/soap/";
        $this->upload = "{$this->host}/service/upload?fmt=raw";

        $this->context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => ['Content-Type: application/json'],
                'content' => null,
                'ignore_errors' => true,
            ],
        ]);
    }

    protected function createAddresses(object $message)
    {
        return array_reduce(
            $message->e,
            function ($result, $address) {
                $result->{$this->types[$address->t]}[] = $address->a;
                return $result;
            },
            (object)array_combine(
                array_values($this->types),
                array_fill(0, count($this->types), []),
            ),
        );
    }

    // Convert a Zimbra message object to a pretty object
    // https://files.zimbra.com/docs/soap_api/8.8.15/api-reference/zimbraMail/Search.html#tbl-SearchResponse-m
    protected function createMessage(object $message)
    {
        return (object)[
            'id' => $message->id,
            'folder' => $message->l, // Folder ID
            'conversation' => $message->cid, // Conversation ID
            'timestamp' => date('Y-m-d H:i:s', $message->d / 1_000), // ms to s
            'subject' => $message->su,
            'addresses' => $this->createAddresses($message),
            'fragment' => $message->fr ?? '',
            'flags' => $message->f ?? '',
            'size' => $message->s,
            // 'revision' => $message->rev,
            'body' => $this->searchBody($message->mp ?? []),
            'attachments' => [],
        ];
    }

    // Structured search to Zimbra query string :
    // ['some search'] => '"some search"'
    // ['in' => '/inbox/subdir', 'date' => '>=-3days'] => 'in:"/inbox/subdir" date:">=-3days"'
    // $parameters can be object or array
    protected function createQueryString($parameters)
    {
        $query = '';

        foreach ($parameters as $name => $value) {
            $value = '"' . str_replace('"', '""', $value) . '"';
            if (is_int($name)) {
                $query .= "{$value} ";
            } else {
                $query .= "{$name}:{$value} ";
            }
        }

        $query = substr($query, 0, -1);

        return $query;
    }

    // Prepares protected http context for unauthenticated request
    // Returns JSON SOAP string
    // i.e. without Header or authToken
    /*
        https://gist.github.com/be1/562195

        * request encoded in UTF-8
        * start with '{' for server to identify JSON content
        * do not include "Envelope" object
        * elements specified as "name": { ... }
        * attributes specified as "name": "value"
        * namespace attribute specified as "_jsns": "ns-uri"
        * element text content specified as "_content": "content"
        * element list specified as "name": [ ... ]

        The response format is XML by default. To change, specify a "format"
        element in the request's Header element with a "type" attribute.
        The value must be either "xml" or "js".
    */
    protected function prepareUnauthenticatedRequest(array $body): string
    {
        $request = ['Body' => $body];
        $request = json_encode($request);
        stream_context_set_option($this->context, 'http', 'content', $request);
        return $request;
    }

    // Prepares protected http context for authenticated request
    // Returns JSON SOAP string
    // i.e. with Header and authToken
    protected function prepareAuthenticatedRequest($body): string
    {
        $request = [
            'Header' => [
                'context' => [
                    '_jsns' => 'urn:zimbra',
                    'authToken' => ['_content' => $this->session],
                ],
            ],
            'Body' => $body,
        ];
        $request = json_encode($request);
        stream_context_set_option($this->context, 'http', 'content', $request);
        return $request;
    }

    // Search body in response message
    // Can be deep depending the message constitution
    // Stop at the 1st record found
    // Ex. : { part: 1, ct: text/plain, s: 22, body: true, content: ... }
    protected function searchBody(array $parts): ?object
    {
        foreach ($parts as $part) {
            if ($part->body ?? null === true) {
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

    // Search attachments in response message
    protected function searchMessageAttachments()
    {

    }

    public static function authenticate(string $host, string $email, string $password)
    {
        $z = new self($host, $email, $password);

        $z->prepareUnauthenticatedRequest([
            'AuthRequest' => [
                '_jsns' => 'urn:zimbraAccount',
                'account' => ['by' => 'name', '_content' => $z->email],
                'password' => ['_content' => $password],
            ],
        ]);

        $response = @file_get_contents($z->soap, false, $z->context);

        if ($response === false) {
            throw new \Exception('Unable to authenticate');
        }

        $response = json_decode($response);

        if (isset($response->Body->Fault)) {
            throw new \Exception('Authentication failed');
        }

        $z->session = $response->Body->AuthResponse->authToken[0]->_content ?? null;

        if ($z->session === null) {
            throw new \Exception('No authentication token retrieved');
        }

        return $z;
    }

    /**
     * https://wiki.zimbra.com/wiki/Zimbra_Web_Client_Search_Tips
     * Parameters :
     * in => folder path
     * under => specifies searching a folder and its sub-folders
     * has => specifies an attribute that the message must have,
     *        the types of object you can specify are "attachment", "phone", or "url",
     *        for example, has:attachment would find all messages
     *        which contain one or more attachments of any type
     * filename => specifies an attachment file name, for example filename:query.txt
     *             would find messages with a file attachment named "query.txt"
     * subject => message subject
     * from => sender address/name
     * to => recipient address/name
     * toccme => Same as "from:" except that it specifies me as one of the people
     *           to whom the email was addressed in the TO: or cc: header
     * cc => ...
     * content => message content
     * not in|subject|from|to => ...
     * term, ...
     * date => ">=-3days" / "yyyy-mm-dd"
     * after => ...
     * before => ...
     * is => read|unread|...
     */
    public function getSearch(array $parameters, int $limit = 1_000, int $offset = 0): array
    {
        $query = $this->createQueryString($parameters);

        $this->prepareAuthenticatedRequest([
            // SearchDirectory request => Zimbra 9
            // Zimbra 8 => https://files.zimbra.com/docs/soap_api/8.8.8/api-reference/index.html
            'SearchRequest' => [
                '_jsns' => 'urn:zimbraMail',
                'types' => 'message',
                'sortBy' => 'dateDesc',
                'fetch' => 'all',
                'limit' => $limit,
                'offset' => $offset,
                'query' => ['_content' => $query],
                'locale' => ['_content' => 'fr_CA'], // For date format yyyy-mm-dd
            ],
        ]);

        $response = file_get_contents($this->soap, false, $this->context);

        $response = json_decode($response);
        $messages = $response->Body->SearchResponse->m ?? [];
        $messages = array_reverse($messages); // Olders first

        file_put_contents(__DIR__ . '/dump-raw.txt', json_encode($messages, JSON_PRETTY_PRINT));

        $result = [];
        foreach ($messages as $message) {
            $result[] = $this->createMessage($message);
        }

        file_put_contents(__DIR__ . '/dump-parsed.txt', json_encode($result, JSON_PRETTY_PRINT));

        return $result;
    }

    // Do a search to get messages
    public function getMessages(string $search)
    {
        $this->prepareAuthenticatedRequest([
            'SearchRequest' => [
                '_jsns' => 'urn:zimbraMail',
                'types' => 'message',
                'sortBy' => 'dateDesc',
                'limit' => 1_000,
                'query' => ['_content' => $search],
            ],
        ]);

        $response = @file_get_contents($this->soap, false, $this->context);

        if ($response === false) {
            throw new \Exception('Unable to get folder contents');
        }

        $response =  json_decode($response);

        $response = $response->Body->SearchResponse->m ?? null;

        if ($response === null) {
            throw new \Exception('List of messages is null');
        }

        // Adresses types
        // (f)rom, (t)o, (c)c, (b)cc, (r)eply-to, (s)ender, read-receipt (n)otification, (rf) resent-from
        // TODO : use that
        $types = [
            'f' => 'From',
            't' => 'To',
            'c' => 'Cc',
            'b' => 'Bcc',
            'r' => 'Reply-to',
            's' => 'Sender, read-receipt',
            'n' => 'Notification',
            'rf' => 'Resent-from',
        ];

        // Messages flags
        // (u)nread, (f)lagged, has (a)ttachment, (r)eplied, (s)ent by me, for(w)arded,
        // calendar in(v)ite, (d)raft, IMAP-\Deleted (x), (n)otification sent, urgent (!), low-priority (?), priority (+)
        // TODO : use that
        $flags = [
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

        $messages = [];

        foreach ($response as $message) {
            $messages[] = $this->createMessage($message);
        }

        $messages = array_reverse($messages);

        return $messages;
    }

    public function getDataSources()
    {
        $request = ['GetDataSourcesRequest' => ['_jsns' => 'urn:zimbraMail']];
        $this->prepareAuthenticatedRequest($request);
        $response = @file_get_contents($this->soap, false, $this->context);
        return $response;
    }

    // Get folder's folders
    public function getFolder(string $name)
    {
        $this->prepareAuthenticatedRequest([
            'GetFolderRequest' => [
                '_jsns' => 'urn:zimbraMail',
                'depth' => 1,
                'folder' => ['path' => $name],
            ],
        ]);

        $response = @file_get_contents($this->soap, false, $this->context);

        if ($response === false) {
            throw new \Exception('Unable to get folder contents');
        }

        return json_decode($response);
    }

    // Get message
    public function getMessage(int $id)
    {
        return (object)[];
    }

    // Get message's attachments
    public function getAttachments(int $id, string $filter)
    {
        return [];
    }

    /**
     * Upload a $stream name $basename to Zimbra upload servlet
     * Stream is read from begining, after reading the pointer is set to end
     * Returns attachment ID (UUID form) on success, or throws an exception on failure
     */
    public function uploadAttachmentStream(string $basename, $stream)
    {
        rewind($stream);
        $buffer = stream_get_contents($stream);
        $aid = $this->uploadAttachmentBuffer($basename, $buffer);
        return $aid;
    }

    /**
     * Upload a $file named $basename to Zimbra upload servlet
     * Returns attachment ID (UUID form) on success, or throws an exception on failure
     */
    public function uploadAttachmentFile(string $basename, string $file)
    {
        $buffer = @file_get_contents($file);

        if ($buffer === false) {
            throw new \Exception("Unable to read file {$file} to attach");
        }

        $aid = $this->uploadAttachmentBuffer($basename, $buffer);

        return $aid;
    }

    /**
     * Upload a $buffer named $basename to Zimbra upload servlet
     * Returns attachment ID (UUID form) on success, or throws an exception on failure
     */
    public function uploadAttachmentBuffer(string $basename, string $buffer)
    {
        $basename_encoded = rawurlencode($basename); // Good encoding for HTTP header value ?

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => [
                    'Content-Type: application/octet-stream',
                    "Content-Disposition: attachment; filename=\"{$basename_encoded}\"",
                    "Cookie: ZM_AUTH_TOKEN={$this->session}",
                    'Content-Transfer-Encoding: binary',
                ],
                'content' => $buffer,
            ],
        ]);

        // raw response : code,client_id,aid
        // raw,extended response gives unvalid CSV, ex. :
        // 200,'null',[{"aid":"63f347f0-df57-...","ct":"text/plain","filename":"name.txt","s":73}]
        // => JSON not delimited and not escaped!
        $response = @file_get_contents($this->upload, false, $context);

        if ($response === false) {
            throw new \Exception("Upload of file {$basename} failed");
        }

        [$code, $request_id, $aid] = str_getcsv($response, ',', "'", '');

        if ($code !== '200') {
            throw new \Exception("Upload of file {$basename} failed with response code {$code}");
        }

        return $aid;
    }

    // Send a message
    public function sendMessage(
        array $addresses, string $subject, string $body,
        array $attachments = [],
        array $options = []
    ) {
        return (object)[];
    }
}