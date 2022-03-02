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

    // Messages' flags
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

    // Internal Zimbra email addresses types
    protected array $types = [
        'f' => 'from',
        't' => 'to',
        'c' => 'cc',
        'b' => 'bcc',
        'r' => 'reply-to',
        's' => 'sender, read-receipt',
        'n' => 'notification', // read-receipt notification
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

    // Convert a Zimbra message object to a pretty object
    // https://files.zimbra.com/docs/soap_api/8.8.15/api-reference/zimbraMail/Search.html#tbl-SearchResponse-m
    protected function createMessage(object $message)
    {
        return (object)[
            'id' => $message->id,
            'mid' => substr($message->mid, 1, -1), // Header message ID without <>, useful for query => "msgid:..."
            'folder' => $message->l, // Folder ID
            'conversation' => $message->cid, // Conversation ID
            'timestamp' => date('Y-m-d H:i:s', $message->d / 1_000), // ms to s
            'subject' => $message->su,
            'addresses' => $this->createAddresses($message),
            'fragment' => $message->fr ?? '',
            'flags' => $message->f ?? '',
            'size' => $message->s,
            'body' => $this->searchBody($message->mp ?? []),
            'attachments' => $this->searchAttachments($message->mp ?? []),
        ];
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

    // Converts a Zimbra part/attachment object to a pretty object
    // https://files.zimbra.com/docs/soap_api/8.8.15/api-reference/zimbraMail/Search.html#tbl-SearchResponse-mp
    protected function createAttachment(object $part)
    {
        return (object)[
            'part' => $part->part,
            'disposition' => $part->cd,
            'type' => $part->ct,
            'size' => $part->s,
            'basename' => $part->filename,
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
        https://wiki.zimbra.com/wiki/Json_format_to_represent_soap

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
    protected function prepareAuthenticatedRequest(array $body): string
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
    protected function searchAttachments(array $parts)
    {
        $attachments = [];

        foreach ($parts as $part) {
            if (in_array($part->cd ?? null, ['inline', 'attachment'])) {
                $attachments[] = $this->createAttachment($part);
            }
            if (isset($part->mp)) {
                $attachments = array_merge(
                    $attachments,
                    $this->searchAttachments($part->mp),
                );
            }
        }

        return $attachments;
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
     * Do a SearchRequest
     * https://files.zimbra.com/docs/soap_api/8.8.15/api-reference/zimbraMail/Search.html
     *
     * $parameters
     *      Array of key/value pairs or single values, to perform a Zimbra search
     *      ['in' => '/Inbox/Important', 'Really important?']
     *      => Search a message containing "Really important?" in folder /Inbox/Important
     *      Misc parameters are possible : https://wiki.zimbra.com/wiki/Zimbra_Web_Client_Search_Tips
     *
     * $limit
     *      Zimbra pager, number of items retrieved
     *      Default and max are 1,000
     *
     * $offset
     *      Zimbra pager, starting retrieving index
     *      Default is 0
     *
     * Some parameters :
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
    public function search(array $parameters, int $limit = 1_000, int $offset = 0): array
    {
        $query = $this->createQueryString($parameters);

        $this->prepareAuthenticatedRequest([
            // SearchDirectory request => Available starting Zimbra 9
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

        $result = [];
        foreach ($messages as $message) {
            $result[] = $this->createMessage($message);
        }

        // Temporary statements, for dev purpose
        file_put_contents(__DIR__ . '/dump-raw.json', json_encode($messages, JSON_PRETTY_PRINT));
        file_put_contents(__DIR__ . '/dump-parsed.json', json_encode($result, JSON_PRETTY_PRINT));

        return $result;
    }

    /**
     * Get folder's folders
     * https://files.zimbra.com/docs/soap_api/8.8.15/api-reference/zimbraMail/GetFolder.html
     * TODO : beautify result
     */
    public function explore(string $name, int $depth = null)
    {
        $this->prepareAuthenticatedRequest([
            'GetFolderRequest' => [
                '_jsns' => 'urn:zimbraMail',
                'depth' => $depth,
                'folder' => [
                    // 'uuid' => $uuid, // Base folder search
                    // 'l' => $id,      // Base folder search
                    'path' => $name,
                ],
            ],
        ]);

        $response = @file_get_contents($this->soap, false, $this->context);

        if ($response === false) {
            throw new \Exception('Unable to get folder contents');
        }

        $response = json_decode($response);

        $folders = $response->Body->GetFolderResponse;

        return $folders;
    }

    // Get message's attachments
    public function getAttachments(int $id, string $filter)
    {
        return [];
    }

    /**
     * Retrieves attachment from message $id attachment and part $part
     * Zimbra REST API used
     * SOAP GetMsgRequest not used because inconsistent
     * (sometimes gives content, sometimes URL)
     * and message-part wrong (request 2, attachment in 2.1)
     */
    public function getAttachment(int $id, string $part)
    {
        $url = "{$this->host}/service/content/get?id={$id}&part={$part}";

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => ["Cookie: ZM_AUTH_TOKEN={$this->session}"],
            ],
        ]);

        $buffer = @file_get_contents($url, false, $context);

        if ($buffer === false) {
            throw new \Exception("Unable to download attachment message ID {$id} part {$part}");
        }

        return $buffer;
    }

    /**
     * Upload a file
     * Returns an attachment ID (UUID form) on success, or throws an exception on failure
     */
    public function uploadAttachment($attachment)
    {
        $attachment = (object)$attachment; // Eventual array to object casting, for flex
        if (isset($attachment->basename, $attachment->file)) { // File upload
            $aid = $this->uploadAttachmentFile($attachment->basename, $attachment->file);
        } elseif (isset($attachment->basename, $attachment->buffer)) { // Buffer upload
            $aid = $this->uploadAttachmentBuffer($attachment->basename, $attachment->buffer);
        } elseif (isset($attachment->basename, $attachment->stream)) { // Stream upload
            $aid = $this->uploadAttachmentStream($attachment->basename, $attachment->stream);
        } else {
            throw new \Exception(
                'Incorrect upload definition, '
                . 'basename must always be provided, '
                . 'and file, buffer or stream'
            );
        }
        return $aid;
    }

    /**
     * Upload multiple files in a row (but with multiple API calls)
     * Returns an array of attachments ID (UUID form)
     */
    public function uploadAttachments(array $attachments)
    {
        $aids = []; // Attachments ID
        foreach ($attachments as $attachment) {
            $aids[] = $this->uploadAttachment($attachment);
        }
        return $aids;
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

    /**
     * Convert adresses to Zimbra format for SOAP request
     * Ex. ['to' => ['user@exemple.net']] to [['t' => 't', 'a' => 'user@exemple.net']]
     */
    protected function prepareAddresses(array $addresses)
    {
        // sfaut\Zimbra type to Zimbra type
        // Ex. ['to' => 't']
        $types = array_flip($this->types);

        $result = [];
        foreach ($addresses as $type => $type_addresses) {
            if (!is_array($type_addresses)) {
                $type_addresses = [$type_addresses];
            }
            foreach ($type_addresses as $address) {
                $result[] = ['t' => $types[$type], 'a' => $address];
            }
        }

        return $result;
    }

    /**
     * Convert uploads array to Zimbra format for SOAP request
     * Returns an array of attachments IDs
     */
    protected function prepareAttachments(array $attachments)
    {
        $aids = [];

        foreach ($attachments as $attachment) {
            if (is_string($attachment)) { // Assuming an attachment ID
                $aids[] = $attachment;
            } else { // File to upload
                $aids[] = $this->uploadAttachment($attachment);
            }
        }

        return $aids;
    }

    /**
     * Send a message
     * https://files.zimbra.com/docs/soap_api/8.8.15/api-reference/zimbraMail/SendMsg.html
     *
     * $addresses
     *      Array [$type => $address/es, etc.], $address/es can be an array of addresses or an unique string address
     *      Ex. ['to' => 'admin@exemple.net', 'cc' => ['ml.exemple.net', 'sup@exemple.net']]
     *
     * $subject
     *      Message subject string
     *
     * $body
     *      Message body string
     *      text/plain by default
     *
     * $attachments
     *      Array, multiple files format are accepted (array or object)
     *      Upload a file : { basename: "file.csv", file: "/path/to/file.csv" }
     *      Upload a buffer : { basename: "file.csv", buffer: $buffer }
     *      Upload a stream : { basename : "file.csv", stream: $stream }
     *      Attach a file previously uploaded with Zimbra::uploadAttachment[Buffer|File|Stream]() : string attachment ID
     *
     * Returns the Zimbra message sent structure
     *
     * TODO: return a sfaut\Zimbra message structure or null if fails
     */
    public function send(
        array $addresses, string $subject, string $body,
        array $attachments = []
    ) {
        $request = [
            'SendMsgRequest' => [
                '_jsns' => 'urn:zimbraMail',
                'noSave' => 0,
                'fetchSavedMsg' => 1,
                'm' => [
                    'e' => $this->prepareAddresses($addresses),
                    'su' => $subject,
                    'f' => $flags ?? '',
                    'mp' => [
                        'ct' => $type ?? 'text/plain',
                        'content' => ['_content' => $body],
                    ],
                ],
            ],
        ];

        // We must not send an empty aid, elsewhere Zimbra fails
        if (!empty($attachments)) {
            $aids = $this->prepareAttachments($attachments);
            $aids = implode(',', $aids);
            $request['SendMsgRequest']['m']['attach']['aid'] = $aids;
        }

        $this->prepareAuthenticatedRequest($request);

        $response = @file_get_contents($this->soap, false, $this->context);

        if ($response === false) {
            throw new \Exception('Error while sending message');
        }

        $response = json_decode($response);

        return $response;
    }
}