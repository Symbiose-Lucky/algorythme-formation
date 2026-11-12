<?php

namespace App\Enum;

enum TypeClient: string
{
    case Entreprise = 'entreprise';
    case Public = 'public';
    case Particulier = 'particulier';

    public function libelle(): string
    {
        return match ($this) {
            self::Entreprise => 'Entreprise',
            self::Public => 'Organisme public',
            self::Particulier => 'Particulier',
        };
    }
}
