<?php

namespace App\Enum;

/**
 * Ce qui se remplit autour d'une session.
 *
 * Quatre types portent sur un participant : trois questionnaires qu'il
 * remplit lui-même, et l'évaluation des acquis, remplie par le formateur.
 * Deux autres recueillent l'appréciation des autres parties prenantes :
 * le client (l'entreprise qui a commandé la formation), le financeur
 * (OPCO, France Travail…) et le formateur.
 */
enum TypeEvaluation: string
{
    case Positionnement = 'positionnement';
    case Acquis = 'acquis';
    case Chaud = 'chaud';
    case Froid = 'froid';
    case Client = 'client';
    case Financeur = 'financeur';
    case Formateur = 'formateur';

    public function libelle(): string
    {
        return match ($this) {
            self::Positionnement => 'Positionnement',
            self::Acquis => 'Évaluation des acquis',
            self::Chaud => 'Satisfaction à chaud',
            self::Froid => 'Satisfaction à froid',
            self::Client => 'Satisfaction du client',
            self::Financeur => 'Satisfaction du financeur',
            self::Formateur => 'Bilan du formateur',
        };
    }

    /** Le titre que lit la personne qui répond. */
    public function titre(): string
    {
        return match ($this) {
            self::Positionnement => 'Avant la formation : où en êtes-vous ?',
            self::Acquis => 'Évaluation des acquis',
            self::Chaud => 'Votre avis sur la formation',
            self::Froid => 'Quelques semaines après : votre bilan',
            self::Client => 'Votre avis sur la formation de vos collaborateurs',
            self::Financeur => 'Votre avis sur la formation que vous avez financée',
            self::Formateur => 'Votre bilan de la session',
        };
    }

    /** Vrai pour ce que la personne remplit pour elle-même : tout, sauf l'évaluation des acquis. */
    public function estQuestionnaire(): bool
    {
        return $this !== self::Acquis;
    }

    /**
     * Ce qui porte sur un participant, dans l'ordre de la formation.
     *
     * @return list<self>
     */
    public static function participants(): array
    {
        return [self::Positionnement, self::Acquis, self::Chaud, self::Froid];
    }

    /**
     * Les questionnaires que le participant remplit lui-même.
     *
     * @return list<self>
     */
    public static function questionnaires(): array
    {
        return [self::Positionnement, self::Chaud, self::Froid];
    }
}
