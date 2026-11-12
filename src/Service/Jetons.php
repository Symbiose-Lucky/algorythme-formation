<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Jetons des liens personnels (émargement) : une empreinte du sujet,
 * calculée avec le secret de l'application. Rien n'est stocké ; un lien
 * ne se devine pas et ne vaut que pour la personne à qui il a été remis.
 */
class Jetons
{
    public function __construct(
        #[Autowire('%kernel.secret%')]
        private readonly string $secret,
    )
    {
    }

    /** @param string $sujet Ce que le jeton autorise : « emargement|p|42 ». */
    public function pour(string $sujet): string
    {
        return substr(hash_hmac('sha256', $sujet, $this->secret), 0, 40);
    }

    public function valide(string $sujet, string $jeton): bool
    {
        return hash_equals($this->pour($sujet), $jeton);
    }
}
