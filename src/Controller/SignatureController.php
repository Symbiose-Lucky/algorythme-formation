<?php

namespace App\Controller;

use App\Controller\Admin\DocumentController;
use App\Entity\Signataire;
use App\Service\Documents;
use App\Service\VueDocument;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Signature d'un document par lien personnel : le signataire lit le PDF,
 * signe à la main, et retrouve ensuite le document signé au même endroit.
 *
 * Le jeton du lien tient lieu d'identification : il a été envoyé à
 * l'adresse email du signataire, et à elle seule.
 */
class SignatureController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Documents              $documents,
    )
    {
    }

    #[Route('/signature/{jeton}', name: 'app_signature', requirements: ['jeton' => '[0-9a-f]{48}'], methods: ['GET', 'POST'])]
    public function signer(string $jeton, Request $request, VueDocument $vue): Response
    {
        $signataire = $this->signataire($jeton);
        $erreur = null;

        if ($request->isMethod('POST')) {
            try {
                $this->documents->signer(
                    $signataire,
                    $request->request->getString('signature'),
                    $request->request->getBoolean('approuve'),
                    $request->getClientIp(),
                    $request->headers->get('User-Agent'),
                );

                $this->addFlash('succes', 'Votre signature est enregistrée. Merci.');

                return $this->redirectToRoute('app_signature', ['jeton' => $jeton]);
            } catch (DomainException $e) {
                $erreur = $e->getMessage();
            }
        }

        return $this->render('signature/document.html.twig', [
            'signataire' => $signataire,
            'document' => $signataire->getDocument(),
            'donnees' => $vue->donnees($signataire->getDocument(), $signataire),
            'erreur' => $erreur,
        ], new Response(status: $erreur === null ? 200 : 422));
    }

    #[Route('/signature/{jeton}/fichier', name: 'app_signature_fichier', requirements: ['jeton' => '[0-9a-f]{48}'], methods: ['GET'])]
    public function fichier(string $jeton): Response
    {
        $document = $this->signataire($jeton)->getDocument();

        return DocumentController::reponseFichier($this->documents->chemin($document), $document, false);
    }

    /** Un lien inconnu, ou celui d'un document archivé, ne mène nulle part. */
    private function signataire(string $jeton): Signataire
    {
        $signataire = $this->em->getRepository(Signataire::class)->findOneBy(['jeton' => $jeton]);

        if ($signataire === null || $signataire->getDocument()->isArchive()) {
            throw $this->createNotFoundException('Lien invalide.');
        }

        return $signataire;
    }
}
