<?php

namespace App\Enum;

/**
 * Inter : des participants de plusieurs entreprises, à date fixe.
 * Intra : une session dédiée aux collaborateurs d'un seul client.
 */
enum FormuleSession: string
{
    case Inter = 'inter';
    case Intra = 'intra';

    public function libelle(): string
    {
        return match ($this) {
            self::Inter => 'Inter-entreprises',
            self::Intra => 'Intra-entreprise',
        };
    }
}
