<?php

namespace App\Controller;

use App\Entity\Formateur;
use App\Entity\SessionFormation;
use App\Enum\StatutSession;
use App\Enum\TypeEvaluation;
use App\Repository\EvaluationRepository;
use App\Service\CatalogueFormations;
use App\Service\Evaluations;
use App\Service\Jetons;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * L'espace du formateur : un lien personnel et permanent, sans compte, où
 * il retrouve toutes ses sessions. Pour chacune : les dates, le groupe, le
 * positionnement des participants (pour adapter la formation), le programme
 * à dispenser, et l'accès à sa feuille de présence, à l'évaluation des
 * acquis et à son bilan.
 *
 * Comme pour l'émargement, le jeton du lien tient lieu d'identification.
 * Un formateur archivé n'a plus d'espace.
 */
class EspaceFormateurController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Jetons                 $jetons,
    )
    {
    }

    /** Le sujet du jeton de l'espace d'un formateur. */
    public static function sujet(Formateur $formateur): string
    {
        return 'espace|f|' . $formateur->getId();
    }

    #[Route('/formateur/{id}/{jeton}', name: 'app_espace_formateur', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function index(int $id, string $jeton): Response
    {
        $formateur = $this->formateur($id, $jeton);
        $aujourdhui = (new DateTimeImmutable('now', new DateTimeZone('Europe/Paris')))->format('Y-m-d');

        $aVenir = [];
        $passees = [];

        foreach ($this->sessions($formateur) as $session) {
            $fin = $session->getDateFin()?->format('Y-m-d');
            $ligne = [
                'session' => $session,
                'inscrits' => count($session->inscrits()),
                'lien' => $this->generateUrl('app_espace_formateur_session', [
                    'id' => $id,
                    'jeton' => $jeton,
                    'session' => $session->getId(),
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

        return $this->render('signature/espace_formateur.html.twig', [
            'formateur' => $formateur,
            'aVenir' => $aVenir,
            'passees' => $passees,
        ]);
    }

    #[Route(
        '/formateur/{id}/{jeton}/session/{session}',
        name: 'app_espace_formateur_session',
        requirements: ['id' => '\d+', 'session' => '\d+'],
        methods: ['GET'],
    )]
    public function session(
        int                  $id,
        string               $jeton,
        int                  $session,
        Evaluations          $evaluations,
        EvaluationRepository $depot,
        CatalogueFormations  $catalogue,
    ): Response
    {
        $formateur = $this->formateur($id, $jeton);
        $session = $this->em->find(SessionFormation::class, $session);

        if ($session === null || !$session->getFormateurs()->contains($formateur)) {
            throw $this->createNotFoundException('Session introuvable.');
        }

        $remplis = $depot->deLaSession($session);
        $positionnements = [];
        $participants = [];

        foreach ($session->inscrits() as $inscription) {
            $positionnement = $remplis[$inscription->getId()][TypeEvaluation::Positionnement->value] ?? null;

            if ($positionnement !== null) {
                $positionnements[] = $positionnement;
            }

            $participants[] = ['inscription' => $inscription, 'positionnement' => $positionnement];
        }

        $fiche = $session->getProgramme()->getContenu();

        return $this->render('signature/espace_formateur_session.html.twig', [
            'formateur' => $formateur,
            'session' => $session,
            'participants' => $participants,
            'synthese' => $evaluations->synthese($positionnements),
            'objectifs' => $evaluations->objectifs($session),
            'deroule' => $catalogue->deroule((string)($fiche['programme'] ?? '')),
            'retour' => $this->generateUrl('app_espace_formateur', ['id' => $id, 'jeton' => $jeton]),
            // Sa page de la session : émargement, évaluation des acquis, bilan.
            'lienSession' => $this->generateUrl('app_emargement_formateur', [
                'session' => $session->getId(),
                'formateur' => $id,
                'jeton' => $this->jetons->pour(EmargementController::sujetFormateur($session, $formateur)),
            ]),
        ]);
    }

    private function formateur(int $id, string $jeton): Formateur
    {
        $formateur = $this->em->find(Formateur::class, $id);

        if ($formateur === null || $formateur->isArchive() || !$this->jetons->valide(self::sujet($formateur), $jeton)) {
            throw $this->createNotFoundException('Lien invalide.');
        }

        return $formateur;
    }

    /**
     * Les sessions que le formateur anime, les plus récentes d'abord.
     *
     * @return list<SessionFormation>
     */
    private function sessions(Formateur $formateur): array
    {
        return $this->em->createQueryBuilder()
            ->select('s')
            ->from(SessionFormation::class, 's')
            ->where(':formateur MEMBER OF s.formateurs')
            ->setParameter('formateur', $formateur)
            ->orderBy('s.dateDebut', 'DESC')
            ->addOrderBy('s.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
