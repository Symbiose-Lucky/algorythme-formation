<?php

namespace App\Service;

use App\Entity\Document;
use App\Entity\Signataire;
use DateTimeZone;

/**
 * Ce que le navigateur reçoit pour afficher un document, ses zones de
 * signature et, à la demande, assembler le PDF signé (contrôleur Stimulus
 * `document`).
 */
class VueDocument
{
    /**
     * @param Signataire|null $lecteur Le signataire qui consulte la page par son lien personnel ;
     *                                 null dans l'admin. Il ne voit ni l'email ni l'adresse IP des autres.
     *
     * @return array<string, mixed>
     */
    public function donnees(Document $document, ?Signataire $lecteur = null): array
    {
        $paris = new DateTimeZone('Europe/Paris');
        $signataires = [];

        $certificat = [
            'Certificat de signature',
            '',
            'Document : ' . $document->getNom(),
            'Fichier d\'origine : ' . $document->getNomOriginal(),
            'Empreinte SHA-256 du fichier d\'origine :',
            $document->getEmpreinte(),
            '',
        ];

        foreach ($document->getSignataires() as $signataire) {
            $signataires[] = [
                'id' => $signataire->getId(),
                'nom' => $signataire->getNom(),
                'zones' => $signataire->getZones(),
                'verrouille' => $signataire->estVerrouille(),
                'signature' => $signataire->getSignature(),
                'moi' => $signataire === $lecteur,
            ];

            if (!$signataire->aSigne()) {
                $certificat[] = $signataire->getNom() . ' : signature en attente';
                $certificat[] = '';
                continue;
            }

            $certificat[] = sprintf(
                '%s : signé le %s (heure de Paris)',
                $signataire->getNom(),
                $signataire->getSigneLe()->setTimezone($paris)->format('d/m/Y à H:i:s'),
            );

            if ($lecteur === null || $lecteur === $signataire) {
                $certificat[] = '   Email : ' . $signataire->getEmail() . ' · Adresse IP : ' . ($signataire->getIp() ?? 'inconnue');
            }

            $certificat[] = '';
        }

        $certificat[] = 'Signature électronique simple : chaque signataire a signé depuis un lien personnel';
        $certificat[] = 'envoyé à son adresse email. Les preuves sont conservées par l\'organisme.';

        return [
            'nom' => $document->getNom(),
            'signataires' => $signataires,
            'certificat' => $certificat,
        ];
    }
}
