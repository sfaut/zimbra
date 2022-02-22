<?php

class Zimbra
{
    /**
     * Connexion au serveur Zimbra
     */
    public function __construct(string $host, string $user, string $password)
    {

    }

    /**
     * Liste les messages d'un dossier
     */
    public function list(string $folder): array
    {

    }

    /**
     * Lecture d'un message
     */
    public function read(int $id): stdClass
    {

    }

    /**
     * Retourne une pièce-jointe
     */
    public function load(int $message, int $attachment): stdClass
    {

    }

    /**
     * Ecrit une pièce-jointe
     */
    public function save(int $message, int $attachment, string $destination = null): string
    {

    }

    /**
     * Envoi d'un message
     */
    public function send(array $recipients, string $subject, array $attachments = [], string $type = 'text/plain')
    {

    }
}
