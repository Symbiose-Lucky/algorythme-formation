<?php

namespace App\Enum;

enum TypeClient: string
{
    case Entreprise = 'entreprise';
    case Public = 'public';
    case Particulier = 'particulier';
    case Financeur = 'financeur';

    public function libelle(): string
    {
        return match ($this) {
            self::Entreprise => 'Entreprise',
            self::Public => 'Organisme public',
            self::Particulier => 'Particulier',
            self::Financeur => 'Financeur (OPCO, France Travail, Région…)',
        };
    }
}
