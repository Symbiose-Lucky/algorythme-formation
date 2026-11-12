<?php

namespace App\Service;

use App\Controller\EmargementController;
use App\Entity\Client;
use App\Entity\Document;
use App\Entity\Inscription;
use App\Entity\Organisme;
use App\Entity\SessionFormation;
use App\Entity\User;
use App\Enum\CategorieDocument;
use App\Enum\TypeEvaluation;
use App\Repository\EmargementRepository;
use App\Repository\EvaluationRepository;
use App\Repository\OrganismeRepository;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Établit les documents d'une session à partir de ses données : programme,
 * convention, convocations, certificats de réalisation. Chaque document
 * généré est rangé comme un document déposé (voir Documents) : il se
 * consulte, s'archive et s'envoie pour signature de la même façon.
 *
 * Régénérer un document archive sa version précédente, sauf si elle porte
 * déjà une signature.
 */
class DocumentsGeneres
{
    /**
     * Cadre de signature du client sur la dernière page de la convention,
     * en millimètres depuis le coin supérieur gauche d'une page A4
     * (210 × 297). Doit rester aligné sur templates/pdf/convention.html.twig.
     */
    private const array CADRE_CLIENT = ['x' => 109, 'y' => 34, 'l' => 77, 'h' => 36];

    public function __construct(
        private readonly GenerateurPdf         $pdf,
        private readonly Documents             $documents,
        private readonly OrganismeRepository   $organismes,
        private readonly EmargementRepository  $emargements,
        private readonly CatalogueFormations   $catalogue,
        private readonly Jetons                $jetons,
        private readonly UrlGeneratorInterface $urls,
        private readonly EvaluationRepository  $evaluations,
    )
    {
    }

    public function programme(SessionFormation $session, ?User $auteur): Document
    {
        return $this->ranger(
            $session,
            'pdf/programme.html.twig',
            $this->contexte($session),
            'Programme - ' . $session->getFormation()->getNom(),
            CategorieDocument::Programme,
            'programme',
            $auteur,
        )['document'];
    }

    /**
     * La convention entre l'organisme et un client, pour ses participants
     * à la session. Si l'interlocuteur du client a une adresse email, il
     * est désigné signataire, sa zone déjà placée : il ne reste qu'à envoyer.
     *
     * @param int $montantHT En centimes.
     *
     * @throws DomainException Si la fiche de l'organisme est incomplète.
     */
    public function convention(SessionFormation $session, Client $client, int $montantHT, ?User $auteur): Document
    {
        $organisme = $this->organismes->unique();
        $this->exigerOrganisme($organisme);

        $tva = (int)round($montantHT * $organisme->getTauxTva() / 100);

        $resultat = $this->ranger(
            $session,
            'pdf/convention.html.twig',
            $this->contexte($session) + [
                'client' => $client,
                'participants' => array_values(array_filter(
                    $session->inscrits(),
                    static fn(Inscription $i) => $i->getClient() === $client,
                )),
                'montantHT' => $montantHT,
                'montantTva' => $tva,
                'montantTTC' => $montantHT + $tva,
            ],
            'Convention - ' . $client->getNom(),
            CategorieDocument::Convention,
            'convention:client:' . $client->getId(),
            $auteur,
        );

        $document = $resultat['document'];

        if ($client->getContactEmail() !== '') {
            $signataire = $this->documents->ajouterSignataire(
                $document,
                $client->getContactNom() ?: $client->getNom(),
                $client->getContactEmail(),
                $auteur,
            );

            $this->documents->enregistrerZones($document, [$signataire->getId() => [[
                'page' => $resultat['pages'],
                'x' => self::CADRE_CLIENT['x'] / 210,
                'y' => self::CADRE_CLIENT['y'] / 297,
                'l' => self::CADRE_CLIENT['l'] / 210,
                'h' => self::CADRE_CLIENT['h'] / 297,
            ]]]);
        }

        return $document;
    }

    /**
     * Une convocation par participant inscrit, avec son lien personnel d'émargement.
     *
     * @return list<Document>
     */
    public function convocations(SessionFormation $session, ?User $auteur): array
    {
        if ($session->getSeances()->isEmpty()) {
            throw new DomainException("Planifiez les séances de la session avant d'établir les convocations.");
        }

        $documents = [];

        foreach ($session->inscrits() as $inscription) {
            $documents[] = $this->ranger(
                $session,
                'pdf/convocation.html.twig',
                $this->contexte($session) + [
                    'participant' => $inscription->getParticipant(),
                    'lienEmargement' => $this->urls->generate('app_emargement_participant', [
                        'id' => $inscription->getId(),
                        'jeton' => $this->jetons->pour(EmargementController::sujetParticipant($inscription)),
                    ], UrlGeneratorInterface::ABSOLUTE_URL),
                ],
                'Convocation - ' . $inscription->getParticipant()->libelle(),
                CategorieDocument::Convocation,
                'convocation:inscription:' . $inscription->getId(),
                $auteur,
            )['document'];
        }

        return $documents;
    }

    /**
     * Un certificat de réalisation par participant ayant émargé au moins
     * une séance. La durée attestée est celle des séances émargées « présent ».
     *
     * @return list<Document>
     *
     * @throws DomainException Si la fiche de l'organisme est incomplète.
     */
    public function certificats(SessionFormation $session, ?User $auteur): array
    {
        $organisme = $this->organismes->unique();
        $this->exigerOrganisme($organisme);

        $documents = [];

        foreach ($this->heuresRealisees($session) as $ligne) {
            if ($ligne['heures'] <= 0) {
                continue;
            }

            $inscription = $ligne['inscription'];

            $documents[] = $this->ranger(
                $session,
                'pdf/certificat.html.twig',
                $this->contexte($session) + [
                    'participant' => $inscription->getParticipant(),
                    'client' => $inscription->getClient(),
                    'heures' => $ligne['heures'],
                    'premierJour' => $ligne['premierJour'],
                    'dernierJour' => $ligne['dernierJour'],
                ],
                'Certificat de réalisation - ' . $inscription->getParticipant()->libelle(),
                CategorieDocument::Attestation,
                'certificat:inscription:' . $inscription->getId(),
                $auteur,
            )['document'];
        }

        if ($documents === []) {
            throw new DomainException("Aucun participant n'a encore émargé : il n'y a rien à attester.");
        }

        return $documents;
    }

    /**
     * Une attestation de fin de formation par participant dont les acquis
     * ont été évalués : objectifs, durée suivie, résultat de l'évaluation.
     *
     * @return list<Document>
     *
     * @throws DomainException Si la fiche de l'organisme est incomplète, ou si personne n'a été évalué.
     */
    public function attestations(SessionFormation $session, ?User $auteur): array
    {
        $organisme = $this->organismes->unique();
        $this->exigerOrganisme($organisme);

        $evaluations = $this->evaluations->deLaSession($session);
        $documents = [];

        foreach ($this->heuresRealisees($session) as $ligne) {
            $inscription = $ligne['inscription'];
            $evaluation = $evaluations[$inscription->getId()][TypeEvaluation::Acquis->value] ?? null;

            if ($evaluation === null || $ligne['heures'] <= 0) {
                continue;
            }

            $documents[] = $this->ranger(
                $session,
                'pdf/attestation.html.twig',
                $this->contexte($session) + [
                    'participant' => $inscription->getParticipant(),
                    'client' => $inscription->getClient(),
                    'heures' => $ligne['heures'],
                    'prevues' => $session->getHeuresPlanifiees(),
                    'premierJour' => $ligne['premierJour'],
                    'dernierJour' => $ligne['dernierJour'],
                    'acquis' => $evaluation->duGenre('acquis'),
                    'commentaire' => (string)$evaluation->valeur('commentaire'),
                    'evaluePar' => $evaluation->getFormateur()?->libelle(),
                    'evalueLe' => $evaluation->getHorodatage()->setTimezone(new DateTimeZone('Europe/Paris')),
                ],
                'Attestation de fin de formation - ' . $inscription->getParticipant()->libelle(),
                CategorieDocument::Attestation,
                'attestation:inscription:' . $inscription->getId(),
                $auteur,
            )['document'];
        }

        if ($documents === []) {
            throw new DomainException(
                "Aucune attestation à établir : il faut que le participant ait émargé et que ses acquis aient été évalués.",
            );
        }

        return $documents;
    }

    /**
     * Pour chaque inscrit, les heures des séances où il a été émargé
     * présent, et les jours extrêmes de sa présence.
     *
     * @return list<array{inscription: Inscription, heures: float, premierJour: ?DateTimeImmutable, dernierJour: ?DateTimeImmutable}>
     */
    public function heuresRealisees(SessionFormation $session): array
    {
        $tableau = $this->emargements->deLaSession($session);
        $lignes = [];

        foreach ($session->inscrits() as $inscription) {
            $minutes = 0;
            $premier = null;
            $dernier = null;

            foreach ($session->seancesTriees() as $seance) {
                $emargement = $tableau[$seance->getId()]['p' . $inscription->getId()] ?? null;

                if ($emargement?->estPresent()) {
                    $minutes += max(0, $seance->minutes());
                    $premier ??= $seance->getJour();
                    $dernier = $seance->getJour();
                }
            }

            $lignes[] = [
                'inscription' => $inscription,
                'heures' => round($minutes / 60, 2),
                'premierJour' => $premier,
                'dernierJour' => $dernier,
            ];
        }

        return $lignes;
    }

    /**
     * Ce que tous les gabarits reçoivent : l'organisme, la session, et le
     * programme tel que la session le dispense (sa version retenue, pas la
     * fiche actuelle de la formation).
     *
     * @return array<string, mixed>
     */
    private function contexte(SessionFormation $session): array
    {
        $fiche = $session->getProgramme()->getContenu();

        return [
            'organisme' => $this->organismes->unique(),
            'session' => $session,
            'fiche' => $fiche,
            'versionProgramme' => $session->getProgramme(),
            'objectifs' => $this->catalogue->puces($fiche['objectifs'] ?? ''),
            'publicVise' => $this->catalogue->puces($fiche['publicVise'] ?? ''),
            'prerequis' => $this->catalogue->puces($fiche['prerequis'] ?? ''),
            'deroule' => $this->catalogue->deroule((string)($fiche['programme'] ?? '')),
            'methodes' => $this->catalogue->puces($fiche['methodes'] ?? ''),
            'moyens' => $this->catalogue->puces($fiche['moyens'] ?? ''),
            'evaluation' => $this->catalogue->puces($fiche['evaluation'] ?? ''),
            'acces' => $this->catalogue->puces($fiche['acces'] ?? ''),
            'accessibilite' => $this->catalogue->puces($fiche['accessibilite'] ?? ''),
            'editeLe' => new DateTimeImmutable('now', new DateTimeZone('Europe/Paris')),
        ];
    }

    /**
     * @param array<string, mixed> $contexte
     *
     * @return array{document: Document, pages: int}
     */
    private function ranger(
        SessionFormation  $session,
        string            $gabarit,
        array             $contexte,
        string            $nom,
        CategorieDocument $categorie,
        string            $origine,
        ?User             $auteur,
    ): array
    {
        $pdf = $this->pdf->generer($gabarit, $contexte);

        return [
            'document' => $this->documents->enregistrer(
                $pdf['contenu'],
                SessionFormation::TYPE_JOURNAL,
                $session->getId(),
                $nom,
                $categorie,
                $origine,
                $auteur,
            ),
            'pages' => $pdf['pages'],
        ];
    }

    private function exigerOrganisme(Organisme $organisme): void
    {
        $manques = $organisme->manques();

        if ($manques !== []) {
            throw new DomainException(
                "Complétez d'abord la fiche de l'organisme : " . mb_strtolower(implode(', ', $manques)) . '.',
            );
        }
    }
}
