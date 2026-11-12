<?php

namespace App\Controller\Admin;

use App\Entity\Organisme;
use App\Entity\User;
use App\Form\OrganismeType;
use App\Repository\EntreeJournalRepository;
use App\Repository\OrganismeRepository;
use App\Service\Journal;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * L'identité de l'organisme et la signature de son représentant, reprises
 * sur les documents générés. Chaque modification est consignée au journal.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/organisme', name: 'app_admin_organisme')]
class OrganismeController extends AbstractController
{
    /** Taille maximale du tracé de signature (image PNG encodée). */
    private const int TRACE_MAX = 400_000;

    public function __construct(
        private readonly OrganismeRepository    $organismes,
        private readonly EntityManagerInterface $em,
        private readonly Journal                $journal,
    )
    {
    }

    #[Route('', name: '', methods: ['GET', 'POST'])]
    public function index(Request $request, EntreeJournalRepository $entrees): Response
    {
        $organisme = $this->organismes->unique();
        $avant = $organisme->instantane();

        $form = $this->createForm(OrganismeType::class, $organisme);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->enregistrer($organisme, $avant);
            $this->addFlash('succes', "Fiche de l'organisme enregistrée.");

            return $this->redirectToRoute('app_admin_organisme');
        }

        return $this->render('admin/organisme/index.html.twig', [
            'organisme' => $organisme,
            'form' => $form,
            'journal' => $entrees->pour(Organisme::TYPE_JOURNAL, $organisme->getId()),
            'champs' => Organisme::CHAMPS,
        ]);
    }

    /** Enregistre la signature tracée à l'écran, ou la retire (`retirer`). */
    #[Route('/signature', name: '_signature', methods: ['POST'])]
    public function signature(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('submit', $request->request->getString('_csrf_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $organisme = $this->organismes->unique();
        $avant = $organisme->instantane();

        if ($request->request->has('retirer')) {
            $organisme->setSignature(null);
            $this->addFlash('succes', 'Signature retirée.');
        } else {
            $image = $request->request->getString('signature');

            if (strlen($image) > self::TRACE_MAX
                || strlen($image) < 200
                || preg_match('#^data:image/png;base64,[A-Za-z0-9+/]+=*$#', $image) !== 1) {
                $this->addFlash('erreur', 'Signez dans le cadre avant de valider.');

                return $this->redirectToRoute('app_admin_organisme', ['_fragment' => 'signature']);
            }

            $organisme->setSignature($image);
            $this->addFlash('succes', 'Signature enregistrée : elle sera apposée sur les prochains documents générés.');
        }

        $this->enregistrer($organisme, $avant);

        return $this->redirectToRoute('app_admin_organisme', ['_fragment' => 'signature']);
    }

    /**
     * @param array<string, string|null> $avant
     */
    private function enregistrer(Organisme $organisme, array $avant): void
    {
        $this->em->flush();

        $user = $this->getUser();

        $this->journal->modification(
            Organisme::TYPE_JOURNAL,
            $organisme->getId(),
            $avant,
            $organisme->instantane(),
            $user instanceof User ? $user : null,
        );
    }
}
