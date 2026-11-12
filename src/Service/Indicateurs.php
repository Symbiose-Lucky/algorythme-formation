<?php

namespace App\Service;

use App\Entity\Emargement;
use App\Entity\Evaluation;
use App\Entity\Formation;
use App\Enum\EtatEmargement;
use App\Enum\StatutSession;
use App\Enum\TypeEvaluation;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Indicateurs de résultats, calculés sur ce que l'outil a enregistré :
 * personnes formées (au moins une présence émargée), satisfaction à chaud,
 * recommandation, objectifs acquis. Les sessions annulées ne comptent pas.
 *
 * Ce sont les chiffres que Qualiopi demande de diffuser (indicateur 2).
 * Sur le site, ils se combinent aux chiffres saisis sur la fiche de la
 * formation, qui tiennent lieu d'historique d'avant l'outil (voir publics()).
 */
class Indicateurs
{
    /**
     * En dessous de ce nombre de réponses, un pourcentage n'est pas publié
     * sur le site : il ne dirait rien de fiable. L'admin, lui, montre tout.
     */
    public const int SEUIL_PUBLICATION = 5;

    private const array VIDE = [
        'sessions' => 0,
        'formes' => 0,
        'reponses' => 0,
        'sommeNotes' => 0,
        'recommandent' => 0,
        'avisRecommandation' => 0,
        'evalues' => 0,
        'objectifs' => 0,
        'objectifsAcquis' => 0,
    ];

    /** @var array<string, array<int, array<string, int>>> Calculs déjà faits, par année (« toutes » sinon). */
    private array $calculs = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
    )
    {
    }

    /**
     * Les chiffres bruts de chaque formation qui a eu de l'activité.
     *
     * - sessions, formes : sessions et participants ayant au moins une présence émargée ;
     * - reponses, sommeNotes : questionnaires à chaud reçus, et somme de leur note globale sur 5 ;
     * - recommandent, avisRecommandation : « oui » à la recommandation, sur le nombre de réponses ;
     * - evalues, objectifs, objectifsAcquis : participants évalués, objectifs évalués, objectifs acquis.
     *
     * @param ?int $annee Ne retenir que les sessions commencées cette année-là.
     *
     * @return array<int, array<string, int>> Par identifiant de formation.
     */
    public function parFormation(?int $annee = null): array
    {
        $cle = $annee === null ? 'toutes' : (string)$annee;

        if (isset($this->calculs[$cle])) {
            return $this->calculs[$cle];
        }

        $lignes = [];

        // Personnes formées : une inscription compte dès qu'une présence est émargée.
        $presences = $this->em->createQueryBuilder()
            ->select('IDENTITY(s.formation) AS formation', 'COUNT(DISTINCT i.id) AS formes', 'COUNT(DISTINCT s.id) AS sessions')
            ->from(Emargement::class, 'e')
            ->join('e.inscription', 'i')
            ->join('i.session', 's')
            ->where('e.etat = :present')
            ->andWhere('s.statut != :annulee')
            ->setParameter('present', EtatEmargement::Present)
            ->setParameter('annulee', StatutSession::Annulee)
            ->groupBy('s.formation');

        // Satisfaction à chaud et évaluation des acquis : les réponses sont lues une à une.
        $evaluations = $this->em->createQueryBuilder()
            ->select('e', 's')
            ->from(Evaluation::class, 'e')
            ->join('e.session', 's')
            ->where('e.type IN (:types)')
            ->andWhere('s.statut != :annulee')
            ->setParameter('types', [TypeEvaluation::Chaud, TypeEvaluation::Acquis])
            ->setParameter('annulee', StatutSession::Annulee);

        if ($annee !== null) {
            foreach ([$presences, $evaluations] as $requete) {
                $requete
                    ->andWhere('s.dateDebut >= :debut')
                    ->andWhere('s.dateDebut < :fin')
                    ->setParameter('debut', new DateTimeImmutable($annee . '-01-01'))
                    ->setParameter('fin', new DateTimeImmutable(($annee + 1) . '-01-01'));
            }
        }

        foreach ($presences->getQuery()->getArrayResult() as $ligne) {
            $lignes[(int)$ligne['formation']] = ['sessions' => (int)$ligne['sessions'], 'formes' => (int)$ligne['formes']] + self::VIDE;
        }

        foreach ($evaluations->getQuery()->getResult() as $evaluation) {
            $id = $evaluation->getSession()->getFormation()->getId();
            $lignes[$id] ??= self::VIDE;

            if ($evaluation->getType() === TypeEvaluation::Chaud) {
                $lignes[$id]['reponses']++;
                $lignes[$id]['sommeNotes'] += (int)$evaluation->valeur('global');

                if ($evaluation->valeur('recommandation') !== null) {
                    $lignes[$id]['avisRecommandation']++;
                    $lignes[$id]['recommandent'] += $evaluation->valeur('recommandation') === 'oui' ? 1 : 0;
                }
            } else {
                $lignes[$id]['evalues']++;

                foreach ($evaluation->duGenre('acquis') as $reponse) {
                    $lignes[$id]['objectifs']++;
                    $lignes[$id]['objectifsAcquis'] += $reponse['valeur'] === 'acquis' ? 1 : 0;
                }
            }
        }

        return $this->calculs[$cle] = $lignes;
    }

    /**
     * Les mêmes chiffres, lisibles : moyenne et pourcentages. Null là où
     * il n'y a rien à calculer.
     *
     * @param array<string, int> $brut Une ligne de parFormation(), ou une somme de lignes.
     *
     * @return array{sessions: int, formes: int, reponses: int, note: ?float, recommandation: ?int, evalues: int, acquis: ?int}
     */
    public function lisible(array $brut): array
    {
        $brut += self::VIDE;

        return [
            'sessions' => $brut['sessions'],
            'formes' => $brut['formes'],
            'reponses' => $brut['reponses'],
            'note' => $brut['reponses'] > 0 ? round($brut['sommeNotes'] / $brut['reponses'], 1) : null,
            'recommandation' => $brut['avisRecommandation'] > 0 ? (int)round($brut['recommandent'] / $brut['avisRecommandation'] * 100) : null,
            'evalues' => $brut['evalues'],
            'acquis' => $brut['objectifs'] > 0 ? (int)round($brut['objectifsAcquis'] / $brut['objectifs'] * 100) : null,
        ];
    }

    /**
     * La somme de toutes les formations.
     *
     * @param array<int, array<string, int>> $parFormation
     *
     * @return array<string, int>
     */
    public function total(array $parFormation): array
    {
        $total = self::VIDE;

        foreach ($parFormation as $ligne) {
            foreach ($total as $cle => $valeur) {
                $total[$cle] = $valeur + ($ligne[$cle] ?? 0);
            }
        }

        return $total;
    }

    /**
     * Ce que le site affiche pour une formation.
     *
     * La note et le nombre de personnes formées saisis sur la fiche sont
     * l'historique d'avant l'outil ; ce que l'outil a enregistré s'y ajoute :
     * - personnes formées : historique + formées dans l'outil ;
     * - note : moyenne de l'historique et des réponses reçues, l'historique
     *   pesant autant que le nombre de personnes qu'il représente (au moins 1) ;
     * - recommandation et objectifs acquis : uniquement d'après l'outil, et
     *   seulement à partir de SEUIL_PUBLICATION réponses.
     *
     * @return array{note: ?float, nbFormes: ?int, recommandation: ?int, acquis: ?int}
     */
    public function publics(Formation $formation): array
    {
        $brut = ($this->parFormation()[$formation->getId()] ?? []) + self::VIDE;
        $lisible = $this->lisible($brut);

        $noteHistorique = $formation->getNote() > 0 ? (float)$formation->getNote() : null;
        $formesHistorique = max(0, (int)$formation->getNbFormes());

        if ($brut['reponses'] === 0) {
            $note = $noteHistorique;
        } elseif ($noteHistorique === null) {
            $note = $lisible['note'];
        } else {
            $poids = max(1, $formesHistorique);
            $note = round(($noteHistorique * $poids + $brut['sommeNotes']) / ($poids + $brut['reponses']), 1);
        }

        $formes = $formesHistorique + $brut['formes'];

        return [
            'note' => $note,
            'nbFormes' => $formes > 0 ? $formes : null,
            'recommandation' => $brut['avisRecommandation'] >= self::SEUIL_PUBLICATION ? $lisible['recommandation'] : null,
            'acquis' => $brut['evalues'] >= self::SEUIL_PUBLICATION ? $lisible['acquis'] : null,
        ];
    }
}
