<?php

namespace App\Service;

use App\Entity\Client;
use App\Entity\Evaluation;
use App\Entity\Formateur;
use App\Entity\Inscription;
use App\Entity\SessionFormation;
use App\Entity\User;
use App\Enum\StatutSession;
use App\Enum\TypeEvaluation;
use App\Repository\EvaluationRepository;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;

/**
 * Questionnaires d'une session et seul point d'écriture de leurs réponses.
 *
 * - Positionnement : le participant situe son niveau sur chaque objectif
 *   du programme, avant la formation.
 * - Satisfaction à chaud : il le situe de nouveau en fin de formation (ce
 *   qui mesure sa progression) et donne son avis.
 * - Satisfaction à froid : quelques semaines plus tard, la mise en pratique.
 * - Évaluation des acquis : le formateur dit, objectif par objectif, ce
 *   que chaque participant a acquis. C'est elle que reprend l'attestation
 *   de fin de formation.
 * - Satisfaction du client et bilan du formateur : l'appréciation des
 *   autres parties prenantes de la session, en fin de formation.
 *
 * Les objectifs sont ceux de la version du programme que la session dispense.
 */
class Evaluations
{
    /** Nombre de jours après la fin de la session avant le questionnaire à froid. */
    public const int DELAI_FROID = 60;

    public const array NIVEAUX = [
        1 => 'Je découvre',
        2 => "J'ai des notions",
        3 => 'Je sais faire avec de l\'aide',
        4 => 'Je sais faire seul(e)',
    ];

    public const array NOTES = [
        1 => 'Pas du tout satisfait',
        2 => 'Peu satisfait',
        3 => 'Moyennement satisfait',
        4 => 'Satisfait',
        5 => 'Très satisfait',
    ];

    public const array ACQUIS = [
        'acquis' => 'Acquis',
        'en_cours' => "En cours d'acquisition",
        'non_acquis' => 'Non acquis',
    ];

    private const int TEXTE_MAX = 2000;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EvaluationRepository   $evaluations,
        private readonly CatalogueFormations    $catalogue,
        private readonly Emargements            $emargements,
        private readonly Journal                $journal,
    )
    {
    }

    /**
     * Les objectifs du programme que la session dispense, un par ligne.
     *
     * @return list<string>
     */
    public function objectifs(SessionFormation $session): array
    {
        $objectifs = array_values($this->catalogue->puces($session->getProgramme()->getContenu()['objectifs'] ?? ''));

        return $objectifs !== []
            ? $objectifs
            : ['Maîtriser le contenu de la formation « ' . $session->getFormation()->getNom() . ' »'];
    }

    /**
     * Les questions d'un questionnaire, dans l'ordre.
     *
     * @return list<array{cle: string, question: string, genre: string, options: array<int|string, string>, rubrique: string}>
     */
    public function questions(TypeEvaluation $type, SessionFormation $session): array
    {
        $questions = [];

        $ajouter = static function (string $cle, string $question, string $genre, string $rubrique, array $options = []) use (&$questions): void {
            $questions[] = ['cle' => $cle, 'question' => $question, 'genre' => $genre, 'options' => $options, 'rubrique' => $rubrique];
        };

        $objectifs = function (string $genre, string $rubrique, array $options) use ($session, $ajouter): void {
            foreach ($this->objectifs($session) as $i => $objectif) {
                $ajouter('objectif_' . $i, $objectif, $genre, $rubrique, $options);
            }
        };

        switch ($type) {
            case TypeEvaluation::Positionnement:
                $objectifs('niveau', "Où en êtes-vous aujourd'hui sur chacun des objectifs de la formation ?", self::NIVEAUX);
                $ajouter('attentes', "Qu'attendez-vous de cette formation ?", 'texte', 'Vos attentes');
                $ajouter('contexte', 'Dans quelles situations de travail comptez-vous vous en servir ?', 'texte', 'Vos attentes');
                break;

            case TypeEvaluation::Chaud:
                $objectifs('niveau', 'Où en êtes-vous à présent sur chacun des objectifs de la formation ?', self::NIVEAUX);
                $avis = 'Votre avis sur la formation';
                $ajouter('organisation', "L'organisation : informations reçues avant la formation, accueil, horaires, lieu ou outil de visioconférence", 'note', $avis, self::NOTES);
                $ajouter('contenu', 'Le contenu : il répondait à vos attentes', 'note', $avis, self::NOTES);
                $ajouter('rythme', 'Le rythme et la durée', 'note', $avis, self::NOTES);
                $ajouter('formateur', 'Le formateur : clarté, pédagogie, disponibilité', 'note', $avis, self::NOTES);
                $ajouter('supports', 'Les supports et les exercices', 'note', $avis, self::NOTES);
                $ajouter('global', 'Votre satisfaction globale', 'note', $avis, self::NOTES);
                $ajouter('recommandation', 'Recommanderiez-vous cette formation ?', 'choix', $avis, ['oui' => 'Oui', 'non' => 'Non']);
                $ajouter('points_forts', 'Ce que vous avez le plus apprécié', 'texte', 'Vos remarques');
                $ajouter('ameliorations', 'Ce que nous pourrions améliorer', 'texte', 'Vos remarques');
                break;

            case TypeEvaluation::Froid:
                $bilan = 'Votre bilan';
                $ajouter('pratique', 'Avez-vous mis en pratique ce que vous avez appris ?', 'choix', $bilan, [
                    'souvent' => 'Oui, régulièrement',
                    'parfois' => 'Oui, de temps en temps',
                    'pas_encore' => 'Pas encore, mais je compte le faire',
                    'non' => 'Non',
                ]);
                $ajouter('utilite', "L'utilité de la formation dans votre travail", 'note', $bilan, self::NOTES);
                $ajouter('attentes', 'Avec le recul, la formation a répondu à vos attentes', 'note', $bilan, self::NOTES);
                $ajouter('freins', "Qu'est-ce qui vous a manqué ou freiné pour mettre en pratique ?", 'texte', 'Vos remarques');
                $ajouter('besoins', "Avez-vous d'autres besoins de formation ?", 'texte', 'Vos remarques');
                break;

            case TypeEvaluation::Client:
                $avis = 'Votre avis';
                $ajouter('preparation', 'La préparation : échanges avant la formation, prise en compte de vos besoins', 'note', $avis, self::NOTES);
                $ajouter('administratif', 'Le suivi administratif : convention, convocations, documents de fin de formation', 'note', $avis, self::NOTES);
                $ajouter('objectifs', 'La formation a répondu aux objectifs que vous lui aviez fixés', 'note', $avis, self::NOTES);
                $ajouter('retours', 'Les retours de vos collaborateurs sur la formation', 'note', $avis, self::NOTES);
                $ajouter('global', 'Votre satisfaction globale', 'note', $avis, self::NOTES);
                $ajouter('recommandation', 'Feriez-vous de nouveau appel à nous ?', 'choix', $avis, ['oui' => 'Oui', 'non' => 'Non']);
                $ajouter('remarques', 'Vos remarques, ce que nous pourrions améliorer', 'texte', 'Vos remarques');
                $ajouter('besoins', "Avez-vous d'autres besoins de formation ?", 'texte', 'Vos remarques');
                break;

            case TypeEvaluation::Formateur:
                $bilan = 'Votre bilan';
                $ajouter('preparation', 'La préparation : informations reçues sur le groupe, ses attentes et son contexte', 'note', $bilan, self::NOTES);
                $ajouter('logistique', 'Les conditions matérielles : salle, équipement, outil de visioconférence', 'note', $bilan, self::NOTES);
                $ajouter('groupe', 'Le groupe : prérequis respectés, niveau homogène, implication', 'note', $bilan, self::NOTES);
                $ajouter('programme', 'Le programme et la durée sont adaptés aux objectifs', 'note', $bilan, self::NOTES);
                $ajouter('organisme', "La relation avec l'organisme de formation", 'note', $bilan, self::NOTES);
                $ajouter('ameliorations', "Ce qu'il faudrait changer dans le programme ou l'organisation", 'texte', 'Vos remarques');
                $ajouter('remarques', 'Difficultés rencontrées, participants à accompagner, autres remarques', 'texte', 'Vos remarques');
                break;

            case TypeEvaluation::Acquis:
                $objectifs('acquis', 'Objectifs de la formation', self::ACQUIS);
                $ajouter('commentaire', 'Commentaire', 'texte', 'Commentaire');
                break;
        }

        return $questions;
    }

    /**
     * Où en est la fenêtre d'un questionnaire : « ouvert », « pas_encore » ou « clos ».
     *
     * - Positionnement : jusqu'au début de la dernière séance.
     * - Acquis, satisfaction à chaud, avis du client et bilan du formateur : à partir du début de la dernière séance.
     * - Satisfaction à froid : DELAI_FROID jours après le dernier jour.
     */
    public function etat(TypeEvaluation $type, SessionFormation $session): string
    {
        if ($session->getStatut() === StatutSession::Annulee) {
            return 'clos';
        }

        $ouverture = $this->ouvreLe($type, $session);
        $maintenant = new DateTimeImmutable('now', new DateTimeZone('Europe/Paris'));

        if ($type === TypeEvaluation::Positionnement) {
            $fin = $this->debutDerniereSeance($session);

            return $fin === null || $maintenant < $fin ? 'ouvert' : 'clos';
        }

        return $ouverture !== null && $ouverture <= $maintenant ? 'ouvert' : 'pas_encore';
    }

    /** Le moment où le questionnaire s'ouvre ; null s'il est ouvert d'emblée ou si la session n'a pas de séance. */
    public function ouvreLe(TypeEvaluation $type, SessionFormation $session): ?DateTimeImmutable
    {
        $derniere = $this->debutDerniereSeance($session);

        return match ($type) {
            TypeEvaluation::Positionnement => null,
            TypeEvaluation::Acquis, TypeEvaluation::Chaud, TypeEvaluation::Client, TypeEvaluation::Formateur => $derniere,
            TypeEvaluation::Froid => $derniere?->setTime(0, 0)->modify('+' . self::DELAI_FROID . ' days'),
        };
    }

    /**
     * Le participant répond à un questionnaire depuis son lien personnel.
     *
     * @param array<string, mixed> $brut Les réponses reçues, par clé de question.
     *
     * @throws DomainException Si le questionnaire est fermé, déjà rempli ou incomplet.
     */
    public function repondre(Inscription $inscription, TypeEvaluation $type, array $brut, ?string $ip, ?string $navigateur): Evaluation
    {
        if (!in_array($type, TypeEvaluation::questionnaires(), true)) {
            throw new \LogicException("Ce questionnaire n'est pas celui d'un participant.");
        }

        if (!$inscription->estActive()) {
            throw new DomainException('Votre inscription à cette session a été annulée.');
        }

        $session = $inscription->getSession();

        if ($this->etat($type, $session) !== 'ouvert') {
            throw new DomainException("Ce questionnaire n'est pas ouvert.");
        }

        if ($this->evaluations->findOneBy(['inscription' => $inscription, 'type' => $type]) !== null) {
            throw new DomainException('Vous avez déjà répondu à ce questionnaire. Merci.');
        }

        $reponses = $this->lire($this->questions($type, $session), $brut);

        $evaluation = new Evaluation($session, $type, $reponses, $inscription);
        $evaluation->attribuer(null, null, $ip, $navigateur);

        $this->em->persist($evaluation);
        $this->em->flush();

        return $evaluation;
    }

    /**
     * Les clients d'une session : celui qui l'a commandée (intra), et ceux
     * des participants inscrits (inter).
     *
     * @return list<Client>
     */
    public function clients(SessionFormation $session): array
    {
        $clients = [];

        if ($session->getClient() !== null) {
            $clients[$session->getClient()->getId()] = $session->getClient();
        }

        foreach ($session->inscrits() as $inscription) {
            if ($inscription->getClient() !== null) {
                $clients[$inscription->getClient()->getId()] = $inscription->getClient();
            }
        }

        return array_values($clients);
    }

    /**
     * Un client donne son avis sur la session, ou un formateur son bilan,
     * depuis son lien personnel. L'un des deux, jamais les deux.
     *
     * @param array<string, mixed> $brut Les réponses reçues, par clé de question.
     *
     * @throws DomainException Si le questionnaire est fermé, déjà rempli ou incomplet.
     */
    public function repondreTiers(
        SessionFormation $session,
        ?Client          $client,
        ?Formateur       $formateur,
        array            $brut,
        ?string          $ip,
        ?string          $navigateur,
    ): Evaluation
    {
        if (($client === null) === ($formateur === null)) {
            throw new \LogicException("L'avis vient d'un client ou d'un formateur.");
        }

        $type = $client !== null ? TypeEvaluation::Client : TypeEvaluation::Formateur;

        if ($this->etat($type, $session) !== 'ouvert') {
            throw new DomainException("Ce questionnaire n'est pas ouvert.");
        }

        if ($this->duTiers($session, $client, $formateur) !== null) {
            throw new DomainException('Vous avez déjà répondu à ce questionnaire. Merci.');
        }

        $reponses = $this->lire($this->questions($type, $session), $brut);

        $evaluation = new Evaluation($session, $type, $reponses, null, $client);
        $evaluation->attribuer($formateur, null, $ip, $navigateur);

        $this->em->persist($evaluation);
        $this->em->flush();

        return $evaluation;
    }

    /** L'avis déjà donné par ce client, ou le bilan déjà donné par ce formateur, sur la session. */
    public function duTiers(SessionFormation $session, ?Client $client, ?Formateur $formateur): ?Evaluation
    {
        $tiers = $this->evaluations->tiersDeLaSession($session);

        return $client !== null
            ? ($tiers['client'][$client->getId()] ?? null)
            : ($tiers['formateur'][$formateur?->getId()] ?? null);
    }

    /**
     * Évalue les acquis des participants d'une session, tous sur un même
     * écran. Un participant dont aucune case n'est cochée est laissé de
     * côté ; un participant évalué à moitié est refusé. Une évaluation
     * identique à celle déjà enregistrée n'est pas réécrite.
     *
     * @param array<int|string, mixed> $niveaux      [idInscription => [cléObjectif => niveau]]
     * @param array<int|string, mixed> $commentaires [idInscription => texte]
     *
     * @return int Le nombre d'évaluations enregistrées ou corrigées.
     *
     * @throws DomainException Si l'évaluation n'est pas ouverte, ou incomplète pour un participant.
     */
    public function evaluer(
        SessionFormation $session,
        array            $niveaux,
        array            $commentaires,
        ?Formateur       $formateur,
        ?User            $auteur,
        ?string          $ip,
        ?string          $navigateur,
    ): int
    {
        if ($this->etat(TypeEvaluation::Acquis, $session) !== 'ouvert') {
            throw new DomainException("Les acquis s'évaluent à partir de la dernière séance de la session.");
        }

        $questions = $this->questions(TypeEvaluation::Acquis, $session);
        $existantes = $this->evaluations->deLaSession($session);
        $aEcrire = [];

        foreach ($session->inscrits() as $inscription) {
            $brut = $niveaux[$inscription->getId()] ?? [];
            $brut = is_array($brut) ? $brut : [];

            if (array_filter($brut, static fn($v) => $v !== '' && $v !== null) === []) {
                continue;
            }

            $brut['commentaire'] = $commentaires[$inscription->getId()] ?? '';

            try {
                $reponses = $this->lire($questions, $brut);
            } catch (DomainException) {
                throw new DomainException(sprintf(
                    'Évaluez tous les objectifs pour %s, ou aucun.',
                    $inscription->getParticipant()->libelle(),
                ));
            }

            $existante = $existantes[$inscription->getId()][TypeEvaluation::Acquis->value] ?? null;

            if ($existante === null || $existante->getReponses() != $reponses) {
                $aEcrire[] = [$inscription, $existante, $reponses];
            }
        }

        foreach ($aEcrire as [$inscription, $existante, $reponses]) {
            $nom = $inscription->getParticipant()->libelle();
            $avant = $existante === null ? 'non évalué' : $this->resume($existante->getReponses());

            if ($existante === null) {
                $existante = new Evaluation($session, TypeEvaluation::Acquis, $reponses, $inscription);
                $this->em->persist($existante);
            } else {
                $existante->corriger($reponses);
            }

            $existante->attribuer($formateur, $auteur?->getEmail(), $ip, $navigateur);
            $this->em->flush();

            $this->journal->modification(
                SessionFormation::TYPE_JOURNAL,
                $session->getId(),
                ['acquis' => $nom . ' : ' . $avant],
                ['acquis' => $nom . ' : ' . $this->resume($reponses) . ' (par ' . $existante->auteur() . ')'],
                $auteur,
            );
        }

        return count($aEcrire);
    }

    /**
     * Ce que disent ensemble les réponses à un questionnaire : moyenne des
     * questions notées, répartition des questions à choix, textes libres.
     * Les questions sont regroupées par leur énoncé.
     *
     * @param list<Evaluation> $evaluations
     *
     * @return array{
     *     nombre: int,
     *     moyennes: array<string, array{genre: string, moyenne: float, nombre: int, sur: int}>,
     *     choix: array<string, array<string, int>>,
     *     textes: array<string, list<array{qui: string, texte: string}>>
     * }
     */
    public function synthese(array $evaluations): array
    {
        $sommes = [];
        $choix = [];
        $textes = [];

        foreach ($evaluations as $evaluation) {
            foreach ($evaluation->getReponses() as $reponse) {
                $question = $reponse['question'];

                switch ($reponse['genre']) {
                    case 'niveau':
                    case 'note':
                        $sommes[$question] ??= ['genre' => $reponse['genre'], 'somme' => 0, 'nombre' => 0];
                        $sommes[$question]['somme'] += (int)$reponse['valeur'];
                        $sommes[$question]['nombre']++;
                        break;

                    case 'choix':
                    case 'acquis':
                        $choix[$question][$reponse['libelle']] = ($choix[$question][$reponse['libelle']] ?? 0) + 1;
                        break;

                    case 'texte':
                        if ($reponse['valeur'] !== '') {
                            $textes[$question][] = ['qui' => $evaluation->auteur(), 'texte' => (string)$reponse['valeur']];
                        }
                        break;
                }
            }
        }

        $moyennes = [];

        foreach ($sommes as $question => $ligne) {
            $moyennes[$question] = [
                'genre' => $ligne['genre'],
                'moyenne' => round($ligne['somme'] / $ligne['nombre'], 1),
                'nombre' => $ligne['nombre'],
                'sur' => $ligne['genre'] === 'niveau' ? count(self::NIVEAUX) : count(self::NOTES),
            ];
        }

        return ['nombre' => count($evaluations), 'moyennes' => $moyennes, 'choix' => $choix, 'textes' => $textes];
    }

    /**
     * La progression déclarée par les participants : pour chaque objectif,
     * le niveau moyen avant (positionnement) et après (satisfaction à
     * chaud), sur ceux qui ont répondu aux deux.
     *
     * @return array{nombre: int, objectifs: array<string, array{avant: float, apres: float}>}
     */
    public function progression(SessionFormation $session): array
    {
        $avant = [];
        $apres = [];
        $nombre = 0;

        foreach ($this->evaluations->deLaSession($session) as $parType) {
            $debut = $parType[TypeEvaluation::Positionnement->value] ?? null;
            $fin = $parType[TypeEvaluation::Chaud->value] ?? null;

            if ($debut === null || $fin === null) {
                continue;
            }

            $nombre++;
            $finales = array_column($fin->duGenre('niveau'), 'valeur', 'question');

            foreach ($debut->duGenre('niveau') as $reponse) {
                if (isset($finales[$reponse['question']])) {
                    $avant[$reponse['question']][] = (int)$reponse['valeur'];
                    $apres[$reponse['question']][] = (int)$finales[$reponse['question']];
                }
            }
        }

        $objectifs = [];

        foreach ($avant as $question => $valeurs) {
            $objectifs[$question] = [
                'avant' => round(array_sum($valeurs) / count($valeurs), 1),
                'apres' => round(array_sum($apres[$question]) / count($valeurs), 1),
            ];
        }

        return ['nombre' => $nombre, 'objectifs' => $objectifs];
    }

    /**
     * Contrôle les réponses reçues et les met en forme pour la conservation.
     * Tout est obligatoire, sauf les textes libres.
     *
     * @param list<array{cle: string, question: string, genre: string, options: array<int|string, string>, rubrique: string}> $questions
     * @param array<string, mixed>                                                                                            $brut
     *
     * @return list<array{cle: string, question: string, genre: string, valeur: int|string, libelle: string}>
     */
    private function lire(array $questions, array $brut): array
    {
        $reponses = [];

        foreach ($questions as $question) {
            $valeur = $brut[$question['cle']] ?? '';
            $valeur = is_scalar($valeur) ? trim((string)$valeur) : '';

            if ($question['genre'] === 'texte') {
                $valeur = mb_substr($valeur, 0, self::TEXTE_MAX);
                $libelle = $valeur;
            } else {
                // Les clés numériques d'un tableau PHP sont des entiers : « 3 » doit retrouver 3.
                $cle = ctype_digit($valeur) ? (int)$valeur : $valeur;

                if ($valeur === '' || !array_key_exists($cle, $question['options'])) {
                    throw new DomainException('Répondez à toutes les questions, sauf les remarques libres, avant de valider.');
                }

                $valeur = $cle;
                $libelle = $question['options'][$cle];
            }

            $reponses[] = [
                'cle' => $question['cle'],
                'question' => $question['question'],
                'genre' => $question['genre'],
                'valeur' => $valeur,
                'libelle' => $libelle,
            ];
        }

        return $reponses;
    }

    /**
     * « 3 acquis, 1 en cours d'acquisition » : une évaluation des acquis en une ligne.
     *
     * @param list<array{cle: string, question: string, genre: string, valeur: int|string, libelle: string}> $reponses
     */
    public function resume(array $reponses): string
    {
        $comptes = [];

        foreach ($reponses as $reponse) {
            if ($reponse['genre'] === 'acquis') {
                $comptes[$reponse['libelle']] = ($comptes[$reponse['libelle']] ?? 0) + 1;
            }
        }

        $morceaux = [];

        foreach (self::ACQUIS as $libelle) {
            if (isset($comptes[$libelle])) {
                $morceaux[] = $comptes[$libelle] . ' ' . mb_strtolower($libelle);
            }
        }

        return implode(', ', $morceaux);
    }

    private function debutDerniereSeance(SessionFormation $session): ?DateTimeImmutable
    {
        $seances = $session->seancesTriees();
        $derniere = end($seances);

        return $derniere === false ? null : $this->emargements->debut($derniere);
    }
}
