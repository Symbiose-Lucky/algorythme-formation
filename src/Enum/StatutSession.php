<?php

namespace App\Enum;

/**
 * Cycle de vie d'une session. Une session n'est jamais supprimée : celle
 * qui n'a pas lieu est annulée, et garde son journal (preuve Qualiopi).
 */
enum StatutSession: string
{
    case Projet = 'projet';
    case Confirmee = 'confirmee';
    case Terminee = 'terminee';
    case Annulee = 'annulee';

    public function libelle(): string
    {
        return match ($this) {
            self::Projet => 'En projet',
            self::Confirmee => 'Confirmée',
            self::Terminee => 'Terminée',
            self::Annulee => 'Annulée',
        };
    }
}
