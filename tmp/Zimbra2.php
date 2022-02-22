<?php

namespace Zimbra;

class Soap
{
    protected string $scheme;
    protected string $host;
    protected string $user;
    protected string $password;
    protected string $url;
    public string $session;     // Jeton de session
    public string $csrf;        // Jeton CSRF, pour les PJ
    //protected $context;

    public function __construct(string $scheme, string $host, string $user, string $password)
    {
        $this->scheme = $scheme;
        $this->host = $host;
        $this->user = $user;
        $this->password = $password;
        $this->url = "{$this->scheme}://{$this->host}/service/soap/";

        $request = [
            'Body' => [
                'AuthRequest' => [
                    '_jsns' => 'urn:zimbraAccount',
                    'account' => ['by' => 'name', '_content' => $this->user],
                    'password' => ['_content' => $this->password],
                ],
            ],
        ];

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => ['Content-Type: application/soap+xml'],
                'content' => json_encode($request),
                'ignore_errors' => true,
            ],
        ]);

        $fp = fopen($this->url, 'r', false, $context);
        $response = stream_get_contents($fp);
        $response = file_get_contents($this->url, false, $context);
        $response = json_decode($response);
        $session = $response->Body->AuthResponse->authToken[0]->_content ?? null;

        $meta = stream_get_meta_data($fp);

        if ($session === null) {
            trigger_error('Authentification impossible, ' . print_r($response, true), E_USER_ERROR);
        }

        $this->session = $session;
    }

    // https://files.zimbra.com/docs/soap_api/9.0.0/api-reference/index.html
    // $addresses => Tableau d'adresses mail
    // Types d'adresses :
    // https://files.zimbra.com/docs/soap_api/9.0.0/api-reference/zimbraMail/SendMsg.html#tbl-SendMsgRequest-m-e-t
    // Clefs : "t" pour "type" => "t" pour "to"
    // Ex. [['t' => 't', 'a' => 'sfaut@reseau.free.fr'], ['t' => 'c', 'a' => 'amassengo@reseau.free.fr']]
    // $attachments => Tableau de PJ
    // Ex. [['filename' => 'extract.csv', 'buffer' => '(contenu du CSV)'], ['filename' => '2nd-extract.csv', 'file' => '/path/to/file.csv']]
    public function send(array $addresses, string $subject, string $body, array $attachments = [], $type = 'text/plain')
    {
        // Gestion des PJ
        // L'upload d'une PJ nécessite un jeton CSRF présent uniquement en dur sur la page
        $aids = []; // Identifiants des attachements uploadés
        if (!empty($attachments)) {
            if (!isset($this->csrf)) {
                $context = stream_context_create(['http' => ['header' => ["Cookie: ZM_AUTH_TOKEN={$this->session}"]]]);
                $page = file_get_contents("{$this->scheme}://{$this->host}/", false, $context);
                preg_match('/window.csrfToken\s*=\s*"([^"]+)"/', $page, $matches);
                $this->csrf = $matches[1];
            }
            $url = "{$this->scheme}://{$this->host}/service/upload?fmt=raw"; // code/message/aid sous forme de ~CSV ,'
            foreach ($attachments as $attachment) {
                $filename = rawurlencode($attachment['filename']);
                $context = stream_context_create([
                    'http' => [
                        'method' => 'POST',
                        'header' => [
                            'Content-Type: application/octet-stream',
                            "Content-Disposition: attachment; filename=\"{$filename}\"",
                            "Cookie: ZM_AUTH_TOKEN={$this->session}",
                            //"X-Zimbra-Csrf-Token: {$this->csrf}",
                            'Content-Transfer-Encoding: binary',
                        ],
                        'content' => $attachment['buffer'] ?? file_get_contents($attachment['file']),
                    ],
                ]);
                $response = file_get_contents($url, false, $context);
                [$code, $request_id, $aid] = str_getcsv($response, ',', '\'', '');
                $aids[] = $aid;
            }
        }

        $request = [
            'Header' => [
                'context' => [
                    '_jsns' => 'urn:zimbra',
                    'authToken' => ['_content' => $this->session],
                ],
            ],
            'Body' => [
                'SendMsgRequest' => [
                    '_jsns' => 'urn:zimbraMail',
                    'm' => [
                        'e' => $addresses,
                        'su' => $subject,
                        'mp' => [
                            'ct' => $type,
                            'content' => ['_content' => $body],
                        ],
                        'attach' => ['aid' => implode(',', $aids)],
                    ],
                ],
            ],
        ];

        // Suppression de l'entrée Body->SendMsgRequest->m->attach si vide
        // (sinon requête Zimbra échoue systématiquement)
        if (empty($attachments)) {
            unset($request['Body']['SendMsgRequest']['m']['attach']);
        }

        $request = json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => ['Content-Type: application/soap+xml'],
                'content' => $request,
                'ignore_errors' => true,
            ],
        ]);

        return file_get_contents($this->url, false, $context);
    }
}
