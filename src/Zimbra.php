<?php

namespace sfaut;

/**
 * Search, read and send Zimbra messages with PHP.
 *
 * Attachments are managed (upload/download).
 * Instances must be created through Zimbra::authenticate(), the constructor
 * is reserved for internal use.
 */
class Zimbra
{
    /**
     * Zimbra scheme and host.
     * Example: "https://zimbra.example.net" or "https://www.example.net/zimbra".
     *
     * @var string
     */
    protected string $host;

    /**
     * Zimbra user account, often an e-mail address.
     * Example: "user@examplet.net" or "user".
     *
     * @var string
     */
    protected string $user;

    /**
     * Zimbra SOAP service endpoint, often "/service/soap".
     *
     * @var string
     */
    protected string $endpointSoap;

    /**
     * Zimbra attachment upload service endpoint, often "/service/upload".
     * Query "?fmt=raw" strips the response HTML and gives data only.
     * Query "?fmt=raw,extended" gives additional informations, but JSON/CSV malformed.
     *
     * @var string
     */
    protected string $endpointUpload;

    /**
     * Zimbra attachment download service endpoint, often "/service/content/get".
     * Query "?id=%d&part=%s" specifies the part/attachment to download.
     * Example: "?id=34299&part=2.1" for user message ID "34299" message part "2.1".
     *
     * @var string
     */
    protected string $endpointContent;

    /**
     * Session auth token, to use in SOAP header.
     * Null until authenticated.
     *
     * @var string|null
     */
    protected ?string $session;

    /**
     * Flags describing the state of the message.
     * Stored in string $message->flags.
     *
     * @var array<string,string>
     * @link https://files.zimbra.com/docs/soap_api/8.8.15/api-reference/zimbraMail/SendMsg.html#tbl-SendMsgResponse-m-f
     */
    protected $flags = [
        'u' => 'Unread',
        'f' => 'Flagged',
        'a' => 'Has attachment',
        'r' => 'Replied',
        's' => 'Sent by me',
        'w' => 'Forwarded',
        'v' => 'Calendar invite',
        'd' => 'Draft',
        'x' => 'IMAP-Deleted',
        'n' => 'Notification sent',
        '!' => 'Urgent',
        '?' => 'Low-priority',
        '+' => 'Priority',
    ];

    /**
     * Zimbra email addresses types.
     *
     * @var array<string,string>
     * @link https://files.zimbra.com/docs/soap_api/8.8.15/api-reference/zimbraMail/SendMsg.html#tbl-SendMsgRequest-m-e-t
     */
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
     * Zimbra SearchRequest sorting capacities.
     * Default "dateDesc".
     *
     * @var string[]
     * @link https://files.zimbra.com/docs/soap_api/8.8.15/api-reference/zimbraMail/Search.html#tbl-SearchRequest-sortBy
     */
    protected array $sorting = [
        'none', // No cursor possible with this
        'dateAsc', 'dateDesc',
        'subjAsc', 'subjDesc',
        'nameAsc', 'nameDesc',
        'rcptAsc', 'rcptDesc',
        'attachAsc', 'attachDesc',
        'flagAsc', 'flagDesc',
        'priorityAsc', 'priorityDesc',
        'idAsc', 'idDesc',
        'readAsc', 'readDesc',
    ];

    /**
     * Instanciate an instance, for internal use only.
     * End user must use Zimbra::authenticate().
     *
     * @param string $host Zimbra scheme and host.
     * @param string $user Zimbra user account, often an e-mail.
     */
    protected function __construct(string $host, string $user)
    {
        $this->host = $host;
        $this->user = $user;

        // Zimbra services endpoints initialization
        $this->endpointSoap = "{$this->host}/service/soap/";
        $this->endpointUpload = "{$this->host}/service/upload?fmt=raw";
        $this->endpointContent = "{$this->host}/service/content/get?id=%s&part=%s";

        // Not authenticated yet
        $this->session = null;
    }

    /**
     * Fetch a POST SOAP request to /service/soap.
     * SOAP header and token session (if not null) are added.
     *
     * From https://gist.github.com/be1/562195 :
     * -- request encoded in UTF-8
     * -- start with '{' for server to identify JSON content
     * -- do not include "Envelope" object
     * -- elements specified as "name": { ... }
     * -- attributes specified as "name": "value"
     * -- namespace attribute specified as "_jsns": "ns-uri"
     * -- element text content specified as "_content": "content"
     * -- element list specified as "name": [ ... ]
     * The response format is XML by default. To change, specify a "format"
     * element in the request's Header element with a "type" attribute.
     * The value must be either "xml" or "js".
     *
     * @param array $body Associative array representing the SOAP body.
     * @return mixed JSON response decoded.
     * @throws \Exception If the SOAP request fetch fails.
     * @link https://gist.github.com/be1/562195
     */
    protected function fetch(array $body)
    {
        // SOAP request construction
        $request = [
            'Header' => ['context' => ['_jsns' => 'urn:zimbra']],
            'Body' => $body,
        ];

        // Add auth token to header if defined
        if ($this->session !== null) {
            $request['Header']['context']['authToken']['_content'] = $this->session;
        }

        $request = json_encode($request);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => ['Content-Type: application/json'],
                'content' => $request,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($this->endpointSoap, false, $context);

        if ($response === false) {
            throw new \Exception('An error occurs while fetching SOAP request');
        }

        $response = json_decode($response);

        return $response;
    }

    /**
     * Convert a Zimbra message object to a pretty object.
     *
     * @param object $message Raw Zimbra message object.
     * @return object Pretty message object.
     * @link https://files.zimbra.com/docs/soap_api/8.8.15/api-reference/zimbraMail/Search.html#tbl-SearchResponse-m
     */
    protected function createMessage(object $message)
    {
        return (object)[
            'id' => $message->id, // User message ID
            'mid' => substr($message->mid, 1, -1), // Server message ID, without trailing <>, useful for search => "msgid:..."
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

    /**
     * Search message addresses and group them by type.
     * Example: { to: [...], cc: [...], ... }.
     *
     * @param object $message Raw Zimbra message object.
     * @return object Addresses grouped by type.
     */
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

    /**
     * Convert a Zimbra part/attachment object to a pretty object.
     *
     * @param object $part Raw Zimbra part object.
     * @return object Pretty attachment object.
     * @link https://files.zimbra.com/docs/soap_api/8.8.15/api-reference/zimbraMail/Search.html#tbl-SearchResponse-mp
     */
    protected function createAttachment(object $part)
    {
        // TODO : check en/decoding HTTP header field-value
        $part->filename = rawurldecode($part->filename);

        return (object)[
            'part' => $part->part,
            'disposition' => $part->cd,
            'type' => $part->ct,
            'size' => $part->s,
            'basename' => $part->filename,
            'filename' => pathinfo($part->filename, PATHINFO_FILENAME),
            'extension' => pathinfo($part->filename, PATHINFO_EXTENSION),
        ];
    }

    /**
     * Prepare structured search to Zimbra query search string.
     * ['some search'] => '"some search"'
     * ['in' => '/Inbox/Subfolder', 'date' => '>=-3days'] => 'in:"/Inbox/Subfolder" date:">=-3days"'
     *
     * @param array|object $parameters Structured search parameters.
     * @return string Zimbra query search string.
     * @link https://gist.github.com/sfaut/deb2c47161e9bebea23386adf55ec609 Zimbra Web Client Search Tips
     */
    protected function prepareSearch($parameters)
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

    /**
     * Search body in response message.
     * Can be deep depending the message constitution.
     * Stops at the 1st record found.
     * Example: { part: 1, ct: text/plain, s: 22, body: true, content: ... }.
     *
     * @param array $parts Zimbra message parts.
     * @return object|null Body object, or null if not found.
     */
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

    /**
     * Search attachments in response message.
     *
     * @param array $parts Zimbra message parts.
     * @return array Array of attachments objects.
     */
    protected function searchAttachments(array $parts)
    {
        $attachments = [];

        foreach ($parts as $part) {
            if (in_array($part->cd ?? null, ['inline', 'attachment']) && isset($part->filename)) {
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

    /**
     * Instanciate Zimbra class and authenticate.
     *
     * @param string $host Zimbra host, example: "https://zimbra.example.net", no trailing slash needed.
     * @param string $user Zimbra user, example: "my-email" or "my-email@free.fr".
     * @param string $password Zimbra user password.
     * @return static Authenticated Zimbra instance.
     * @throws \Exception If authentication fails or no token is retrieved.
     */
    public static function authenticate(string $host, string $user, string $password)
    {
        $z = new self($host, $user);

        $request = [
            'AuthRequest' => [
                '_jsns' => 'urn:zimbraAccount',
                'account' => ['by' => 'name', '_content' => $z->user],
                'password' => ['_content' => $password],
            ],
        ];

        $response = $z->fetch($request);

        if ($response === false) {
            throw new \Exception('Unable to authenticate');
        }

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
     * Do a SearchRequest.
     *
     * @param array $parameters Array of key/value pairs or single values, to perform a Zimbra search.
     *                          Example: ['in' => '/Inbox/Important', 'Really important?']
     *                          searches a message containing "Really important?" in folder /Inbox/Important.
     *                          Misc parameters are possible, see https://wiki.zimbra.com/wiki/Zimbra_Web_Client_Search_Tips
     *
     *                          Some parameters :
     *                          in => folder path
     *                          under => specifies searching a folder and its sub-folders
     *                          has => specifies an attribute that the message must have,
     *                                 the types of object you can specify are "attachment", "phone", or "url",
     *                                 for example, has:attachment would find all messages
     *                                 which contain one or more attachments of any type
     *                          filename => specifies an attachment file name, for example filename:query.txt
     *                                      would find messages with a file attachment named "query.txt"
     *                          subject => message subject
     *                          from => sender address/name
     *                          to => recipient address/name
     *                          toccme => Same as "from:" except that it specifies me as one of the people
     *                                    to whom the email was addressed in the TO: or cc: header
     *                          cc => ...
     *                          content => message content
     *                          not in|subject|from|to => ...
     *                          term, ...
     *                          date => ">=-3days" / "yyyy-mm-dd"
     *                          after => ...
     *                          before => ...
     *                          is => read|unread|...
     * @param int $limit Zimbra pager, number of items retrieved. Default and max are 1,000.
     * @param int $offset Zimbra pager, starting retrieving index. Default is 0.
     * @param bool $is_raw How parameters are managed.
     *                     true : parameters keys and values are used like
     *                     ['to' => 'you@example.net', 'E-mail subject'] => « to:"me@example.net" "E-mail subject" »
     *                     false : parameters are just concatenated :
     *                     ['to:you@example.net', '"E-mail subject"'] => « to:you@example.net "E-mail subject" »
     *                     Default is false.
     * @return array Array of pretty message objects.
     * @throws \Exception If the underlying SOAP request fetch fails.
     * @link https://files.zimbra.com/docs/soap_api/8.8.15/api-reference/zimbraMail/Search.html
     * @todo Customize sort keys and sort orders.
     */
    public function search(array $parameters, int $limit = 1_000, int $offset = 0, bool $is_raw = false): array
    {
        if (!$is_raw) {
            // Use the $parameters' keys and quote the parameters
            $query = $this->prepareSearch($parameters);
        } else {
            // The $paramters' keys are not used and no auto-quoting
            // Just concatenate the parameters
            $query = implode(' ', $parameters);
        }

        $request = [
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
        ];

        $response = $this->fetch($request);

        $messages = $response->Body->SearchResponse->m ?? [];

        // TODO : customize sort keys and sort orders
        $messages = array_reverse($messages); // Olders first

        $result = [];
        foreach ($messages as $message) {
            $result[] = $this->createMessage($message);
        }

        // Temporary statements, for dev purpose
        // file_put_contents(__DIR__ . '/dump-raw.json', json_encode($messages, JSON_PRETTY_PRINT));
        // file_put_contents(__DIR__ . '/dump-parsed.json', json_encode($result, JSON_PRETTY_PRINT));

        return $result;
    }

    /**
     * Get folder's folders.
     *
     * @param string $name Folder path.
     * @param int|null $depth Exploration depth.
     * @return object Zimbra GetFolderResponse.
     * @throws \Exception If no folder response is provided.
     * @link https://files.zimbra.com/docs/soap_api/8.8.15/api-reference/zimbraMail/GetFolder.html
     * @todo Beautify result.
     */
    public function explore(string $name, int $depth = null)
    {
        $request = [
            'GetFolderRequest' => [
                '_jsns' => 'urn:zimbraMail',
                'depth' => $depth,
                'folder' => [
                    // 'uuid' => $uuid, // Base folder search
                    // 'l' => $id,      // Base folder search
                    'path' => $name,
                ],
            ],
        ];

        $response = $this->fetch($request);

        $folders = $response->Body->GetFolderResponse ?? false;

        if ($folders === false) {
            throw new \Exception('No folder response provided');
        }

        return $folders;
    }

    /**
     * Retrieve attachments from $message according to $filter closure.
     * By default retrieves all attachments.
     *
     * @param object $message Pretty message object, as returned by search().
     * @param callable|null $filter Closure(object $attachment): bool, called for each attachment.
     *                              Attachment is retrieved only if it returns true. Null retrieves all attachments.
     * @return array Array of attachments objects to which a stream property has been added.
     * @throws \Exception If an attachment download fails.
     */
    public function download(object $message, callable $filter = null): array
    {
        if ($filter === null) {
            $filter = fn ($attachment) => true;
        }

        $context = [
            'http' => [
                'method' => 'GET',
                'header' => ["Cookie: ZM_AUTH_TOKEN={$this->session}"],
            ],
        ];

        $context = stream_context_create($context);

        $attachments = [];

        foreach ($message->attachments as $attachment) {
            if ($filter($attachment)) {
                $url = sprintf($this->endpointContent, $message->id, $attachment->part);
                $stream_source = @fopen($url, 'r', false, $context);
                if ($stream_source === false) {
                    throw new \Exception(
                        'Unable to download attachment from '
                        . "message ID {$message->id} part {$message->part}"
                    );
                }
                $attachment->stream = tmpfile(); // Open as "w+"
                stream_copy_to_stream($stream_source, $attachment->stream);
                rewind($attachment->stream); // Mandatory, otherwise file pointer at end
                $attachments[] = $attachment;
            }
        }

        return $attachments;
    }

    /**
     * Upload files in a row (but with multiple API calls) to attach to messages.
     *
     * @param array $attachments Array of object|array attachments { basename, buffer|stream|file }.
     * @return array Array of objects attachments with attachment-id (2 × UUID) property added.
     * @throws \Exception If an attachment definition is invalid or an upload fails.
     */
    public function upload(array $attachments): array
    {
        $aids = []; // Result array

        foreach ($attachments as $attachment) {

            $attachment = (object)$attachment; // Eventual array to object casting, for flex

            if (isset($attachment->basename, $attachment->file)) {
                // File upload
                $buffer = @file_get_contents($attachment->file);
                if ($buffer === false) {
                    throw new \Exception("Unable to retrieve file {$attachment->file} contents");
                }
            } elseif (isset($attachment->basename, $attachment->buffer)) {
                // Buffer upload
                $buffer = $attachment->buffer;
            } elseif (isset($attachment->basename, $attachment->stream)) {
                // Stream upload
                $type = @get_resource_type($attachment->stream);
                if (!in_array($type, ['file', 'stream'])) {
                    throw new \Exception(
                        "Upload for {$attachment->basename} failed "
                        . 'because stream is not a valid resource'
                    );
                }
                rewind($attachment->stream);
                $buffer = stream_get_contents($attachment->stream);
            } else {
                throw new \Exception(
                    'Incorrect upload definition, '
                    . 'basename must always be provided, '
                    . 'and file, buffer or stream'
                );
            }

            $basename_encoded = rawurlencode($attachment->basename);

            $context = [
                'http' => [
                    'method' => 'POST',
                    'header' => [
                        'Content-Type: application/octet-stream',
                        "Content-Disposition: attachment; filename*=utf8''{$basename_encoded}",
                        "Cookie: ZM_AUTH_TOKEN={$this->session}",
                        'Content-Transfer-Encoding: binary',
                    ],
                    'content' => $buffer,
                ],
            ];

            $context = stream_context_create($context);

            // raw response : code,client_id,aid
            // raw,extended response gives unvalid CSV, ex. :
            // 200,'null',[{"aid":"63f347f0-df57-...","ct":"text/plain","filename":"name.txt","s":73}]
            // => JSON not delimited and not escaped!
            $response = @file_get_contents($this->endpointUpload, false, $context);

            if ($response === false) {
                throw new \Exception("Upload of file {$attachment->basename} failed");
            }

            [$code, $request_id, $aid] = str_getcsv($response, ',', "'", '');

            if ($code !== '200') {
                throw new \Exception("Upload of file {$attachment->basename} failed with response code {$code}");
            }

            $attachment->id = $aid;

            $aids[] = $attachment;
        }

        return $aids;
    }

    /**
     * Convert adresses to Zimbra format for SOAP request.
     * Example: ['to' => ['user@exemple.net']] to [['t' => 't', 'a' => 'user@exemple.net']]
     *
     * @param array $addresses Addresses grouped by type.
     * @return array Addresses in Zimbra SOAP format.
     */
    protected function prepareAddresses(array $addresses)
    {
        // sfaut\Zimbra type to Zimbra type
        // Example: ['to' => 't']
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
     * Convert attachments array to Zimbra format for SOAP request.
     * Upload files if not already uploaded (if they don't have "id" property).
     *
     * @param array $attachments Array of object|array attachments.
     * @return array Array of attachments IDs.
     */
    protected function prepareAttachments(array $attachments): array
    {
        $aids = [];

        foreach ($attachments as $attachment) {
            $attachment = (object)$attachment;
            if (isset($attachment->id)) {
                // Attachment already uploaded
                $aids[] = $attachment->id;
            } else {
                // Attachment to upload
                $attachment = $this->upload([$attachment]);
                $aids[] = $attachment[0]->id;
            }
        }

        return $aids;
    }

    /**
     * Send a message.
     *
     * @param array $addresses Array [$type => $address/es, etc.], $address/es can be an array of addresses
     *                         or an unique string address.
     *                         Example: ['to' => 'admin@exemple.net', 'cc' => ['ml.exemple.net', 'sup@exemple.net']]
     * @param string $subject Message subject string.
     * @param string $body Message body string, text/plain by default.
     * @param array $attachments Array, multiple files format are accepted (array or object).
     *                           Upload a file: { basename: "file.csv", file: "/path/to/file.csv" }.
     *                           Upload a buffer: { basename: "file.csv", buffer: $buffer }.
     *                           Upload a stream: { basename : "file.csv", stream: $stream }.
     *                           Attach a file already uploaded via Zimbra::upload(): reuse the returned
     *                           object, which carries an "id" property.
     * @return mixed Zimbra message sent structure.
     * @throws \Exception If an attachment upload or the underlying SOAP request fetch fails.
     * @link https://files.zimbra.com/docs/soap_api/8.8.15/api-reference/zimbraMail/SendMsg.html
     * @todo Return a sfaut\Zimbra message structure or null if fails.
     * @todo Send text/html messages.
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
                    'f' => $flags ?? '', // TODO : implement that
                    'mp' => [
                        'ct' => $type ?? 'text/plain', // TODO : flex that
                        'content' => ['_content' => $body],
                    ],
                ],
            ],
        ];

        // We must not send an empty aid, elsewhere Zimbra miserably fails
        if (!empty($attachments)) {
            $aids = $this->prepareAttachments($attachments);
            $aids = implode(',', $aids);
            $request['SendMsgRequest']['m']['attach']['aid'] = $aids;
        }

        $response = $this->fetch($request);

        return $response;
    }
}
