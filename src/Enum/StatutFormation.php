<?php

namespace App\Enum;

/**
 * Cycle de vie d'une formation. Une formation n'est jamais supprimée :
 * retirée du catalogue, elle est archivée, et son historique reste
 * consultable (preuve Qualiopi).
 */
enum StatutFormation: string
{
    case Brouillon = 'brouillon';
    case Publiee = 'publiee';
    case Archivee = 'archivee';

    public function libelle(): string
    {
        return match ($this) {
            self::Brouillon => 'Brouillon',
            self::Publiee => 'Publiée',
            self::Archivee => 'Archivée',
        };
    }
}
