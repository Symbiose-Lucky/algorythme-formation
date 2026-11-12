<?php

namespace App\Controller\Admin;

use App\Entity\Document;
use App\Entity\Signataire;
use App\Entity\User;
use App\Enum\CategorieDocument;
use App\Repository\DocumentRepository;
use App\Service\Documents;
use App\Service\Rattachements;
use App\Service\VueDocument;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Documents de l'admin : dépôt sur la fiche d'un objet, consultation, et,
 * pour un PDF, préparation de la signature (signataires, zones, envoi).
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/documents', name: 'app_admin_document')]
class DocumentController extends AbstractController
{
    public function __construct(
        private readonly Documents     $documents,
        private readonly Rattachements $rattachements,
    )
    {
    }

    /**
     * La carte « Documents » de la fiche d'un objet. Appelée depuis les
     * gabarits par `render(controller(...))` : elle n'a pas de route.
     */
    public function carte(string $type, int $id, DocumentRepository $depot): Response
    {
        return $this->render('admin/document/_carte.html.twig', [
            'type' => $type,
            'id' => $id,
            'documents' => $depot->de($type, $id),
            'categories' => CategorieDocument::cases(),
        ]);
    }

    #[Route('/deposer', name: '_deposer', methods: ['POST'])]
    public function deposer(Request $request): Response
    {
        $this->verifierJeton($request);

        $type = $request->request->getString('type');
        $id = $request->request->getInt('id');
        $objet = $this->rattachements->objet($type, $id);

        if ($objet === null) {
            throw $this->createNotFoundException('Objet introuvable.');
        }

        $fichier = $request->files->get('fichier');

        try {
            if (!$fichier instanceof UploadedFile) {
                throw new DomainException('Choisissez un fichier à déposer.');
            }

            $document = $this->documents->deposer(
                $fichier,
                $type,
                $id,
                $request->request->getString('nom'),
                CategorieDocument::tryFrom($request->request->getString('categorie')) ?? CategorieDocument::Autre,
                $this->auteur(),
            );

            $this->addFlash('succes', sprintf('« %s » est déposé.', $document->getNom()));

            // Un PDF s'ouvre aussitôt : c'est là que l'on prépare sa signature.
            if ($document->estPdf()) {
                return $this->redirectToRoute('app_admin_document_voir', ['id' => $document->getId()]);
            }
        } catch (DomainException $e) {
            $this->addFlash('erreur', $e->getMessage());
        }

        return $this->redirectToRoute($this->rattachements->route($type), ['id' => $id, '_fragment' => 'documents']);
    }

    #[Route('/{id}', name: '_voir', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function voir(Document $document, VueDocument $vue): Response
    {
        $objet = $this->rattachements->objet($document->getObjetType(), $document->getObjetId());

        $dejaSignataires = array_map(static fn(Signataire $s) => $s->getEmail(), $document->getSignataires()->toArray());

        return $this->render('admin/document/voir.html.twig', [
            'document' => $document,
            'donnees' => $vue->donnees($document),
            'retour' => $objet === null ? null : [
                'libelle' => $this->rattachements->libelle($objet),
                'url' => $this->generateUrl($this->rattachements->route($document->getObjetType()), [
                    'id' => $document->getObjetId(),
                    '_fragment' => 'documents',
                ]),
            ],
            'contacts' => $objet === null ? [] : array_values(array_filter(
                $this->rattachements->contacts($objet),
                static fn(array $contact) => !in_array($contact['email'], $dejaSignataires, true),
            )),
        ]);
    }

    /** Le fichier d'origine. `?telecharger=1` force l'enregistrement plutôt que l'affichage. */
    #[Route('/{id}/fichier', name: '_fichier', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function fichier(Document $document, Request $request): Response
    {
        return self::reponseFichier($this->documents->chemin($document), $document, $request->query->getBoolean('telecharger'));
    }

    #[Route('/{id}/archiver', name: '_archiver', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function archiver(Document $document, Request $request): Response
    {
        $this->verifierJeton($request);
        $this->documents->archiver($document, !$document->isArchive(), $this->auteur());

        return $this->redirectToRoute('app_admin_document_voir', ['id' => $document->getId()]);
    }

    #[Route('/{id}/signataires', name: '_signataire', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function signataire(Document $document, Request $request): Response
    {
        $this->verifierJeton($request);

        try {
            $this->documents->ajouterSignataire(
                $document,
                $request->request->getString('nom'),
                $request->request->getString('email'),
                $this->auteur(),
            );
        } catch (DomainException $e) {
            $this->addFlash('erreur', $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_document_voir', ['id' => $document->getId()]);
    }

    #[Route('/{id}/signataires/{signataire}/retirer', name: '_retirer', requirements: ['id' => '\d+', 'signataire' => '\d+'], methods: ['POST'])]
    public function retirer(Document $document, int $signataire, Request $request, EntityManagerInterface $em): Response
    {
        $this->verifierJeton($request);

        $signataire = $em->find(Signataire::class, $signataire);

        if ($signataire === null || $signataire->getDocument() !== $document) {
            throw $this->createNotFoundException('Signataire introuvable.');
        }

        try {
            $this->documents->retirerSignataire($signataire, $this->auteur());
        } catch (DomainException $e) {
            $this->addFlash('erreur', $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_document_voir', ['id' => $document->getId()]);
    }

    /** Enregistre les zones placées à l'écran ; `envoyer` les enregistre puis envoie les demandes. */
    #[Route('/{id}/zones', name: '_zones', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function zones(Document $document, Request $request): Response
    {
        $this->verifierJeton($request);

        $zones = json_decode($request->request->getString('zones'), true);
        $this->documents->enregistrerZones($document, is_array($zones) ? $zones : []);

        if (!$request->request->has('envoyer')) {
            $this->addFlash('succes', 'Zones de signature enregistrées.');

            return $this->redirectToRoute('app_admin_document_voir', ['id' => $document->getId()]);
        }

        try {
            $resultat = $this->documents->envoyer($document, $this->auteur());

            if ($resultat['envoyes'] !== []) {
                $this->addFlash('succes', 'Demande de signature envoyée à : ' . implode(', ', $resultat['envoyes']) . '.');
            }

            if ($resultat['echecs'] !== []) {
                $this->addFlash('erreur', "L'envoi a échoué pour : " . implode(', ', $resultat['echecs']) . '.');
            }
        } catch (DomainException $e) {
            $this->addFlash('erreur', $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_document_voir', ['id' => $document->getId()]);
    }

    /**
     * Sert le fichier depuis le dossier des documents, hors du dossier
     * public. Partagé avec la page de signature.
     */
    public static function reponseFichier(string $chemin, Document $document, bool $telecharger): Response
    {
        if (!is_file($chemin)) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException('Fichier introuvable.');
        }

        $reponse = new BinaryFileResponse($chemin);
        $reponse->headers->set('Content-Type', $document->getMime());
        $reponse->headers->set('X-Content-Type-Options', 'nosniff');
        $reponse->headers->set('Cache-Control', 'private, no-store');

        // Seul un PDF s'affiche dans le navigateur ; tout le reste se télécharge.
        $reponse->setContentDisposition(
            !$telecharger && $document->estPdf()
                ? ResponseHeaderBag::DISPOSITION_INLINE
                : ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $document->getNomOriginal(),
            preg_replace('/[^A-Za-z0-9._-]+/', '_', $document->getNomOriginal()) ?: 'document',
        );

        return $reponse;
    }

    private function verifierJeton(Request $request): void
    {
        if (!$this->isCsrfTokenValid('submit', $request->request->getString('_csrf_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }
    }

    private function auteur(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }
}
