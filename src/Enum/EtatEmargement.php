<?php

namespace App\Enum;

enum EtatEmargement: string
{
    case Present = 'present';
    case Absent = 'absent';

    public function libelle(): string
    {
        return match ($this) {
            self::Present => 'Présent',
            self::Absent => 'Absent',
        };
    }
}
