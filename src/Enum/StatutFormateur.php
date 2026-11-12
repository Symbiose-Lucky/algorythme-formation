<?php

namespace App\Enum;

/**
 * Un formateur indépendant est un sous-traitant : Qualiopi demande alors
 * de pouvoir justifier du contrat et du suivi de sa prestation.
 */
enum StatutFormateur: string
{
    case Salarie = 'salarie';
    case Independant = 'independant';

    public function libelle(): string
    {
        return match ($this) {
            self::Salarie => 'Salarié',
            self::Independant => 'Indépendant (sous-traitant)',
        };
    }
}
