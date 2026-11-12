<?php

namespace App\Controller\Admin;

use App\Entity\Client;
use App\Entity\Document;
use App\Entity\SessionFormation;
use App\Entity\User;
use App\Enum\FormuleSession;
use App\Repository\DocumentRepository;
use App\Repository\EvaluationRepository;
use App\Repository\OrganismeRepository;
use App\Service\Documents;
use App\Service\DocumentsGeneres;
use App\Service\MailService;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Throwable;

/**
 * Génération des documents d'une session : programme, convention par
 * client, convocations, certificats de réalisation, attestations ; et
 * envoi par email, à chaque participant, de ceux qui lui reviennent.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/sessions/{id}/documents', name: 'app_admin_generation', requirements: ['id' => '\d+'])]
class GenerationController extends AbstractController
{
    /** Les documents remis à chaque participant : modèle => préfixe de l'origine du document. */
    private const array NOMINATIFS = [
        'convocations' => 'convocation:inscription:',
        'certificats' => 'certificat:inscription:',
        'attestations' => 'attestation:inscription:',
    ];

    public function __construct(
        private readonly DocumentsGeneres $generes,
    )
    {
    }

    #[Route('', name: '', methods: ['GET'])]
    public function index(
        SessionFormation     $session,
        DocumentRepository   $depot,
        OrganismeRepository  $organismes,
        EvaluationRepository $evaluations,
    ): Response
    {
        // Le dernier document en vigueur de chaque origine : « convention:client:3 » => Document.
        $existants = [];

        foreach ($depot->de(SessionFormation::TYPE_JOURNAL, $session->getId()) as $document) {
            if ($document->getOrigine() !== null && !$document->isArchive()) {
                $existants[$document->getOrigine()] ??= $document;
            }
        }

        $conventions = [];

        foreach ($this->clients($session) as $client) {
            $nombre = count(array_filter($session->inscrits(), static fn($i) => $i->getClient() === $client));

            $conventions[] = [
                'client' => $client,
                'participants' => $nombre,
                'montant' => $this->montantPropose($session, $nombre),
                'document' => $existants['convention:client:' . $client->getId()] ?? null,
            ];
        }

        return $this->render('admin/generation/index.html.twig', [
            'session' => $session,
            'manques' => $organismes->unique()->manques(),
            'signatureOrganisme' => $organismes->unique()->getSignature() !== null,
            'existants' => $existants,
            'conventions' => $conventions,
            'heures' => $this->generes->heuresRealisees($session),
            'evaluations' => $evaluations->deLaSession($session),
        ]);
    }

    #[Route('/generer', name: '_generer', methods: ['POST'])]
    public function generer(SessionFormation $session, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('submit', $request->request->getString('_csrf_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $user = $this->getUser();
        $auteur = $user instanceof User ? $user : null;

        try {
            switch ($request->request->getString('modele')) {
                case 'programme':
                    $document = $this->generes->programme($session, $auteur);
                    $this->addFlash('succes', 'Programme généré.');

                    return $this->versDocument($document);

                case 'convention':
                    $client = array_find(
                        $this->clients($session),
                        static fn(Client $c) => $c->getId() === $request->request->getInt('client'),
                    );

                    if ($client === null) {
                        throw new DomainException('Client introuvable pour cette session.');
                    }

                    $montant = (float)str_replace([' ', ','], ['', '.'], $request->request->getString('montant'));

                    if ($montant <= 0) {
                        throw new DomainException('Indiquez le prix hors taxes de la formation pour ce client.');
                    }

                    $document = $this->generes->convention($session, $client, (int)round($montant * 100), $auteur);
                    $this->addFlash('succes', $document->getSignataires()->isEmpty()
                        ? "Convention générée. Le client n'a pas d'interlocuteur avec une adresse email : désignez le signataire à la main."
                        : 'Convention générée, zone de signature du client placée : il ne reste qu\'à l\'envoyer.');

                    return $this->versDocument($document);

                case 'convocations':
                    $nombre = count($this->generes->convocations($session, $auteur));
                    $this->addFlash('succes', sprintf('%d convocation(s) générée(s).', $nombre));
                    break;

                case 'certificats':
                    $nombre = count($this->generes->certificats($session, $auteur));
                    $this->addFlash('succes', sprintf('%d certificat(s) de réalisation généré(s).', $nombre));
                    break;

                case 'attestations':
                    $nombre = count($this->generes->attestations($session, $auteur));
                    $this->addFlash('succes', sprintf('%d attestation(s) de fin de formation générée(s).', $nombre));
                    break;

                default:
                    throw $this->createNotFoundException('Modèle inconnu.');
            }
        } catch (DomainException $e) {
            $this->addFlash('erreur', $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_generation', ['id' => $session->getId()]);
    }

    /**
     * Envoie à chaque participant, en pièce jointe, son document en vigueur
     * d'un modèle donné. Un document déjà envoyé ne repart pas : pour le
     * renvoyer, le générer à nouveau.
     */
    #[Route('/envoyer', name: '_envoyer', methods: ['POST'])]
    public function envoyer(
        SessionFormation   $session,
        Request            $request,
        DocumentRepository $depot,
        Documents          $documents,
        MailService        $mails,
    ): Response
    {
        if (!$this->isCsrfTokenValid('submit', $request->request->getString('_csrf_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $modele = $request->request->getString('modele');
        $prefixe = self::NOMINATIFS[$modele] ?? null;

        if ($prefixe === null) {
            throw $this->createNotFoundException('Modèle inconnu.');
        }

        $user = $this->getUser();
        $auteur = $user instanceof User ? $user : null;

        $existants = [];

        foreach ($depot->de(SessionFormation::TYPE_JOURNAL, $session->getId()) as $document) {
            if ($document->getOrigine() !== null && !$document->isArchive()) {
                $existants[$document->getOrigine()] ??= $document;
            }
        }

        $envoyes = 0;
        $sansEmail = [];
        $echecs = [];

        foreach ($session->inscrits() as $inscription) {
            $document = $existants[$prefixe . $inscription->getId()] ?? null;

            if ($document === null || $document->getEnvoyeLe() !== null) {
                continue;
            }

            $participant = $inscription->getParticipant();

            if ((string)$participant->getEmail() === '') {
                $sansEmail[] = $participant->libelle();
                continue;
            }

            try {
                $mails->sendDocumentSession(
                    $participant->getEmail(),
                    $session,
                    $modele,
                    $document->getNom() . '.pdf',
                    (string)file_get_contents($documents->chemin($document)),
                );
                $documents->noterEnvoi($document, $participant->getEmail(), $auteur);
                $envoyes++;
            } catch (Throwable) {
                $echecs[] = $participant->libelle();
            }
        }

        if ($envoyes > 0) {
            $this->addFlash('succes', sprintf('%d document(s) envoyé(s) par email.', $envoyes));
        } elseif ($sansEmail === [] && $echecs === []) {
            $this->addFlash('succes', 'Rien à envoyer : tout est déjà parti, ou rien n\'est encore généré.');
        }

        if ($sansEmail !== []) {
            $this->addFlash('erreur', 'Sans adresse email, à remettre autrement : ' . implode(', ', $sansEmail) . '.');
        }

        if ($echecs !== []) {
            $this->addFlash('erreur', "L'envoi a échoué pour : " . implode(', ', $echecs) . '.');
        }

        return $this->redirectToRoute('app_admin_generation', ['id' => $session->getId()]);
    }

    /**
     * Les clients à qui établir une convention : celui de la session, et
     * ceux des participants inscrits (session inter).
     *
     * @return list<Client>
     */
    private function clients(SessionFormation $session): array
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
     * Le prix proposé, d'après les tarifs de la formation : le tarif intra
     * pour le groupe, ou le tarif inter par participant. En centimes ; null
     * sans tarif. Il reste modifiable avant de générer.
     */
    private function montantPropose(SessionFormation $session, int $participants): ?int
    {
        $formation = $session->getFormation();

        if ($session->getFormule() === FormuleSession::Intra) {
            return $formation->getTarifIntraHT();
        }

        return $formation->getTarifInterHT() === null ? null : $formation->getTarifInterHT() * max(1, $participants);
    }

    private function versDocument(Document $document): Response
    {
        return $this->redirectToRoute('app_admin_document_voir', ['id' => $document->getId()]);
    }
}
