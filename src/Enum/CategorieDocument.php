<?php

namespace App\Enum;

/**
 * La nature d'un document déposé : sert à le retrouver, et à montrer à
 * l'auditeur que chaque pièce attendue est bien là.
 */
enum CategorieDocument: string
{
    case Convention = 'convention';
    case Devis = 'devis';
    case Convocation = 'convocation';
    case Programme = 'programme';
    case Emargement = 'emargement';
    case Evaluation = 'evaluation';
    case Attestation = 'attestation';
    case Contrat = 'contrat';
    case Cv = 'cv';
    case Diplome = 'diplome';
    case Autre = 'autre';

    public function libelle(): string
    {
        return match ($this) {
            self::Convention => 'Convention de formation',
            self::Devis => 'Devis',
            self::Convocation => 'Convocation',
            self::Programme => 'Programme',
            self::Emargement => "Feuille d'émargement",
            self::Evaluation => 'Évaluation',
            self::Attestation => 'Attestation, certificat',
            self::Contrat => 'Contrat de sous-traitance',
            self::Cv => 'CV',
            self::Diplome => 'Diplôme, certification',
            self::Autre => 'Autre',
        };
    }
}
