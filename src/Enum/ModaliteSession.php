<?php

namespace App\Enum;

enum ModaliteSession: string
{
    case Presentiel = 'presentiel';
    case Distanciel = 'distanciel';
    case Mixte = 'mixte';

    public function libelle(): string
    {
        return match ($this) {
            self::Presentiel => 'Présentiel',
            self::Distanciel => 'Distanciel',
            self::Mixte => 'Mixte',
        };
    }
}
