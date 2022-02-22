<?php

// Dépendance : ext PECL mailparse pour ZimbraRest::getAttachments()
// $ sudo apt install php-mailparse

namespace Zimbra;

// Dépendance : ext PECL mailparse
// $ sudo apt install php-mailparse

// folder   => Littéral
// subject  => Expression rationnelle
// file     => Expression rationnelle

class Rest
{
    protected $host;
    protected $user;
    protected $password;
    protected $url;
    protected $context;

    // Drapeaux apposés sur les messages :
    // (u)nread, (f)lagged, has (a)ttachment, (r)eplied, (s)ent by me, for(w)arded,
    // calendar in(v)ite, (d)raft, IMAP-\Deleted (x), (n)otification sent, urgent (!), low-priority (?), priority (+)
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
        'f' => 'From',
        't' => 'To',
        'c' => 'Cc',
        'b' => 'Bcc',
        'r' => 'Reply-to',
        's' => 'Sender, read-receipt',
        'n' => 'Notification',
        'rf' => 'Resent-from',
    ];

    public function __construct($host, $user, $password)
    {
        $this->host = $host;
        $this->user = $user;
        $this->password = $password;
        $this->url = "https://$host/home/$user";

        $this->context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => [
                    'Authorization: Basic ' . base64_encode("{$this->user}:{$this->password}"),
                ],
                'ignore_errors' => true,
            ],
        ]);
    }

    /**
     * Récupère les mails d'un répertoire
     * $name : chemin absolu du répertoire, ex. "/inbox/extracts"
     * $query : recherche, ex. "subject:Extracts+Interventions"
     *
     * Drapeaux sur le message : (u)nread, (f)lagged, has (a)ttachment, (r)eplied, (s)ent by me, for(w)arded, calendar in(v)ite, (d)raft, IMAP-\Deleted (x), (n)otification sent, urgent (!), low-priority (?), priority (+)
     * Drapeaux sur l'adresse : (f)rom, (t)o, (c)c, (b)cc, (r)eply-to, (s)ender, read-receipt (n)otification, (rf) resent-from
     */
    public function getFolder(string $name, string $query = null): array
    {
        $url = $this->url . $name . '?auth=ba&fmt=json';
        if (!is_null($query)) {
            $url .= '&query=' . $query;
        }
        $folder = @file_get_contents($url, false, $this->context);
        // HTTP 401 + Warning si répertoire inexistant
        if ($folder === false) {
            return [];
        }
        $folder = json_decode($folder, true);
        $messages = [];
        foreach ($folder['m'] ?? [] as $message) {
            if (!isset($message['f'])) {
//                exit(print_r($message));
            }
            $messages[] = [
                'id' => $message['id'],
                'folder' => $message['l'],
                'conversation' => $message['cid'],
                'timestamp' => date('Y-m-d H:i:s', $message['d'] / 1_000), // ms to s
                'subject' => $message['su'],
                'addresses' => array_map(fn($address) => [
                    'address' => $address['a'],
                    'name' => $address['d'],
                    'type' => $address['t'],
                ], $message['e']),
                'to' => array_reduce($message['e'], function($carry, $address) {
                    if ($address['t'] === 't') {
                        $carry[] = [
                            'address' => $address['a'],
                            'name' => $address['d'],
                            'type' => $address['t'],
                        ];
                    }
                    return $carry;
                }, []),
                'from' => array_reduce($message['e'], fn($carry, $address) => ($address['t'] === 'f') ? [
                    'address' => $address['a'],
                    'name' => $address['d'],
                    'type' => $address['t'],
                ] : $carry, null),
                'fragment' => $message['fr'] ?? '',
                'flags' => $message['f'] ?? '',
                'size' => $message['s'],
                'revision' => $message['rev'],
            ];
        }
        return $messages;
    }

    /**
     * Récupère un mail selon son ID
     * Format JSON
     * Attention contenu du mail incomplet en JSON/XML
     * Passer par Zimbra\Rest::getRawMessage() pour obtenir le contenu complet
     */
    public function getMessage(int $id): ?array
    {
        $url = "{$this->url}?auth=ba&fmt=json&id={$id}"; // Message incomplet en format JSON ou XML
        $message = file_get_contents($url, false, $this->context);
        $message = json_decode($message, true)['m'][0];
        return $message;
    }

    /**
     * Récupère un mail brut selon son ID
     * Utile car pas de contenu du mail en JSON/XML, que le fragment
     */
    public function getRawMessage(int $id): string
    {
        $url = "{$this->url}?auth=ba&id={$id}";
        $message = file_get_contents($url, false, $this->context);
        return $message;
    }

    /**
     * Récupère les pièces-jointes d'un mail selon son ID
     * Retour : [
     *     filename : nom du fichier
     *     type : type MIME du fichier
     *     size : taille du fichier en octets
     *     contents : contenu binaire du fichier
     * ]
     * Filtre sur le nom de fichier selon la regexp $filter
     *
     * TODO
     * Problème : On doit télécharger le mail brut complet pour vérifier les PJ et leur nom
     * À voir si on peut récupérer les méta (API SOAP ?) plutôt que de télécharger systématiquement les fichiers
     */
    public function getAttachments(int $id, string $filter = null): array
    {
        if (!extension_loaded('mailparse')) {
            trigger_error('Extension PECL mailparse nécessaire à l\'analyse des mails', E_USER_ERROR);
        }
        $message = $this->getRawMessage($id);
        $parser = mailparse_msg_create();
        mailparse_msg_parse($parser, $message);
        $structure = mailparse_msg_get_structure($parser); // Ex. ["1", "1.1", "1.2"]
        $attachments = [];
        foreach ($structure as $part_label) {
            $part = mailparse_msg_get_part($parser, $part_label);
            $part_data = mailparse_msg_get_part_data($part);
            if (isset($part_data['disposition-filename'])) { // Potentiel fichier à télécharger ?
                if ($filter === null || preg_match($filter, imap_utf8($part_data['disposition-filename'])) === 1) {
                    // Décodage du contenu "7bit", "base64", etc. effectué par mailparse_msg_extract_part()
                    $contents = mailparse_msg_extract_part($part, $message, null);
                    $attachments[] = [
                        // Ex. "=?utf-8?Q?R=C3=A9fsPTO=2Ecsv?=" => "RéfsPTO.csv"
                        // Voir aussi imap_mime_header_decode()
                        'filename' => imap_utf8($part_data['disposition-filename']),
                        'type' => $part_data['content-type'],
                        'size' => strlen($contents),
                        'contents' => $contents,
                    ];
                } else {
                    // Filtre défini et le nom de fichier de la PJ ne correspond pas au filtre
                    // => On itère sur la PJ suivante
                }
            }
        }
        mailparse_msg_free($parser);
        return $attachments;
    }
}
