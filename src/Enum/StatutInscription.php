<?php

namespace App\Enum;

/**
 * Une inscription n'est jamais supprimée : un participant qui se désiste
 * garde sa ligne, au statut « Annulée ».
 */
enum StatutInscription: string
{
    case Inscrit = 'inscrit';
    case Annulee = 'annulee';

    public function libelle(): string
    {
        return match ($this) {
            self::Inscrit => 'Inscrit',
            self::Annulee => 'Annulée',
        };
    }
}
