<?php

namespace App\Controller;

use App\Controller\Admin\DocumentController;
use App\Entity\Document;
use App\Entity\Inscription;
use App\Entity\Participant;
use App\Entity\SessionFormation;
use App\Enum\StatutSession;
use App\Repository\DocumentRepository;
use App\Service\CatalogueFormations;
use App\Service\Documents;
use App\Service\Evaluations;
use App\Service\Jetons;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * L'espace du participant : un lien personnel et permanent, sans compte,
 * où il retrouve toutes ses formations. Pour chacune : les dates, le lieu,
 * le programme, ses documents (convocation, certificat de réalisation,
 * attestation de fin de formation), et l'accès à sa feuille de présence
 * et à ses questionnaires.
 *
 * Comme pour l'émargement, le jeton du lien tient lieu d'identification.
 * Un participant archivé n'a plus d'espace.
 */
class EspaceParticipantController extends AbstractController
{
    /** Les documents remis au participant : préfixe de leur origine => ce qu'ils sont. */
    private const array DOCUMENTS = [
        'convocation:inscription:' => 'Convocation',
        'certificat:inscription:' => 'Certificat de réalisation',
        'attestation:inscription:' => 'Attestation de fin de formation',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Jetons                 $jetons,
        private readonly DocumentRepository     $depot,
    )
    {
    }

    /** Le sujet du jeton de l'espace d'un participant. */
    public static function sujet(Participant $participant): string
    {
        return 'espace|p|' . $participant->getId();
    }

    #[Route('/participant/{id}/{jeton}', name: 'app_espace_participant', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function index(int $id, string $jeton): Response
    {
        $participant = $this->participant($id, $jeton);
        $aujourdhui = (new DateTimeImmutable('now', new DateTimeZone('Europe/Paris')))->format('Y-m-d');

        $aVenir = [];
        $passees = [];

        foreach ($this->inscriptions($participant) as $inscription) {
            $session = $inscription->getSession();
            $fin = $session->getDateFin()?->format('Y-m-d');
            $ligne = [
                'session' => $session,
                'documents' => count($this->documents($inscription)),
                'lien' => $this->generateUrl('app_espace_participant_formation', [
                    'id' => $id,
                    'jeton' => $jeton,
                    'inscription' => $inscription->getId(),
                ]),
            ];

            // Sans date, la session reste à venir ; annulée, elle est rangée avec les passées.
            if ($session->getStatut() !== StatutSession::Annulee && ($fin === null || $fin >= $aujourdhui)) {
                $aVenir[] = $ligne;
            } else {
                $passees[] = $ligne;
            }
        }

        // Les prochaines d'abord ; les sessions sans date en dernier.
        usort($aVenir, static fn(array $a, array $b) => [$a['session']->getDateDebut() === null, $a['session']->getDateDebut()]
            <=> [$b['session']->getDateDebut() === null, $b['session']->getDateDebut()]);

        return $this->render('signature/espace_participant.html.twig', [
            'participant' => $participant,
            'aVenir' => $aVenir,
            'passees' => $passees,
        ]);
    }

    #[Route(
        '/participant/{id}/{jeton}/formation/{inscription}',
        name: 'app_espace_participant_formation',
        requirements: ['id' => '\d+', 'inscription' => '\d+'],
        methods: ['GET'],
    )]
    public function formation(int $id, string $jeton, int $inscription, Evaluations $evaluations, CatalogueFormations $catalogue): Response
    {
        $participant = $this->participant($id, $jeton);
        $inscription = $this->em->find(Inscription::class, $inscription);

        if ($inscription === null || $inscription->getParticipant() !== $participant || !$inscription->estActive()) {
            throw $this->createNotFoundException('Formation introuvable.');
        }

        $session = $inscription->getSession();
        $documents = [];

        foreach ($this->documents($inscription) as $libelle => $document) {
            $documents[] = [
                'libelle' => $libelle,
                'document' => $document,
                'lien' => $this->generateUrl('app_espace_participant_document', [
                    'id' => $id,
                    'jeton' => $jeton,
                    'document' => $document->getId(),
                ]),
            ];
        }

        return $this->render('signature/espace_participant_formation.html.twig', [
            'participant' => $participant,
            'session' => $session,
            'documents' => $documents,
            'objectifs' => $evaluations->objectifs($session),
            'deroule' => $catalogue->deroule((string)($session->getProgramme()->getContenu()['programme'] ?? '')),
            'retour' => $this->generateUrl('app_espace_participant', ['id' => $id, 'jeton' => $jeton]),
            // Sa page de la session : émargement et questionnaires.
            'lienSession' => $this->generateUrl('app_emargement_participant', [
                'id' => $inscription->getId(),
                'jeton' => $this->jetons->pour(EmargementController::sujetParticipant($inscription)),
            ]),
        ]);
    }

    /** Un document du participant : affiché dans le navigateur, téléchargeable de là. */
    #[Route(
        '/participant/{id}/{jeton}/document/{document}',
        name: 'app_espace_participant_document',
        requirements: ['id' => '\d+', 'document' => '\d+'],
        methods: ['GET'],
    )]
    public function document(int $id, string $jeton, int $document, Documents $documents): Response
    {
        $participant = $this->participant($id, $jeton);
        $document = $this->depot->find($document);

        // Le document doit être l'un de ceux, en vigueur, d'une inscription de ce participant.
        $autorise = false;

        if ($document instanceof Document) {
            foreach ($this->inscriptions($participant) as $inscription) {
                $autorise = $autorise || in_array($document, $this->documents($inscription), true);
            }
        }

        if (!$autorise) {
            throw $this->createNotFoundException('Document introuvable.');
        }

        return DocumentController::reponseFichier($documents->chemin($document), $document, false);
    }

    private function participant(int $id, string $jeton): Participant
    {
        $participant = $this->em->find(Participant::class, $id);

        if ($participant === null || $participant->isArchive() || !$this->jetons->valide(self::sujet($participant), $jeton)) {
            throw $this->createNotFoundException('Lien invalide.');
        }

        return $participant;
    }

    /**
     * Les inscriptions en cours du participant, les sessions les plus récentes d'abord.
     *
     * @return list<Inscription>
     */
    private function inscriptions(Participant $participant): array
    {
        $inscriptions = array_filter(
            $this->em->getRepository(Inscription::class)->findBy(['participant' => $participant]),
            static fn(Inscription $inscription) => $inscription->estActive(),
        );

        usort($inscriptions, static fn(Inscription $a, Inscription $b) => [$b->getSession()->getDateDebut(), $b->getId()]
            <=> [$a->getSession()->getDateDebut(), $a->getId()]);

        return $inscriptions;
    }

    /**
     * Les documents en vigueur remis au participant pour une inscription,
     * par libellé : la convocation, puis le certificat, puis l'attestation.
     *
     * @return array<string, Document>
     */
    private function documents(Inscription $inscription): array
    {
        $documents = [];
        $enVigueur = $this->depot->findBy([
            'objetType' => SessionFormation::TYPE_JOURNAL,
            'objetId' => $inscription->getSession()->getId(),
            'archive' => false,
        ], ['id' => 'DESC']);

        foreach (self::DOCUMENTS as $prefixe => $libelle) {
            foreach ($enVigueur as $document) {
                if ($document->getOrigine() === $prefixe . $inscription->getId()) {
                    $documents[$libelle] = $document;
                    break;
                }
            }
        }

        return $documents;
    }
}
