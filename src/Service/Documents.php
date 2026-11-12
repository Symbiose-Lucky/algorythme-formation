<?php

namespace App\Service;

use App\Entity\Document;
use App\Entity\Signataire;
use App\Entity\User;
use App\Enum\CategorieDocument;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Throwable;

/**
 * Seul point d'écriture des documents : dépôt, archivage, désignation des
 * signataires, placement des zones, envoi et signature. Chaque étape
 * laisse une entrée dans le journal de l'objet auquel le document est
 * rattaché.
 */
class Documents
{
    /** Taille maximale d'un fichier déposé. */
    public const int TAILLE_MAX = 20_000_000;

    /** Types de fichier acceptés, avec l'extension sous laquelle ils sont rangés. */
    private const array TYPES = [
        'application/pdf' => 'pdf',
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    ];

    private const int ZONES_MAX = 20;

    /** Taille maximale d'un tracé de signature (image PNG encodée). */
    private const int TRACE_MAX = 400_000;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Journal                $journal,
        private readonly MailService            $mails,
        private readonly UrlGeneratorInterface  $urls,
        #[Autowire('%app_dossier_documents%')]
        private readonly string                 $dossier,
    )
    {
    }

    /**
     * Range le fichier hors du dossier public et crée le document.
     *
     * @throws DomainException Si le fichier est refusé (type, taille, envoi incomplet).
     */
    public function deposer(
        UploadedFile      $fichier,
        string            $objetType,
        int               $objetId,
        string            $nom,
        CategorieDocument $categorie,
        ?User             $auteur,
    ): Document
    {
        if (!$fichier->isValid()) {
            throw new DomainException("Le fichier n'a pas été reçu en entier (il dépasse peut-être la taille autorisée par le serveur).");
        }

        if ($fichier->getSize() > self::TAILLE_MAX) {
            throw new DomainException('Le fichier dépasse 20 Mo.');
        }

        // Le type est lu dans le contenu du fichier, pas dans son nom.
        $mime = (string)$fichier->getMimeType();

        if (!isset(self::TYPES[$mime])) {
            throw new DomainException('Type de fichier refusé. Sont acceptés : PDF, image PNG ou JPEG, Word (.docx), Excel (.xlsx).');
        }

        $nomOriginal = mb_substr($fichier->getClientOriginalName(), 0, 255);
        $nom = trim($nom) !== '' ? trim($nom) : pathinfo($nomOriginal, PATHINFO_FILENAME);

        $taille = (int)$fichier->getSize();
        $empreinte = hash_file('sha256', $fichier->getPathname());

        $sousDossier = date('Y/m');
        $nomRange = bin2hex(random_bytes(16)) . '.' . self::TYPES[$mime];
        $fichier->move($this->dossier . '/' . $sousDossier, $nomRange);

        $document = new Document(
            $objetType,
            $objetId,
            mb_substr($nom, 0, 200),
            $categorie,
            $sousDossier . '/' . $nomRange,
            $nomOriginal,
            $mime,
            $taille,
            $empreinte,
            $auteur?->getEmail(),
        );

        $this->em->persist($document);
        $this->em->flush();

        $this->consigner($document, '', 'Déposé : ' . $document->getNom(), $auteur);

        return $document;
    }

    /**
     * Range un PDF généré par l'application (voir DocumentsGeneres). La
     * version précédente de même origine est archivée, sauf si quelqu'un
     * l'a déjà signée : une pièce signée reste en vigueur.
     */
    public function enregistrer(
        string            $contenu,
        string            $objetType,
        int               $objetId,
        string            $nom,
        CategorieDocument $categorie,
        string            $origine,
        ?User             $auteur,
    ): Document
    {
        $sousDossier = date('Y/m');
        $nomRange = bin2hex(random_bytes(16)) . '.pdf';

        if (!is_dir($this->dossier . '/' . $sousDossier)) {
            mkdir($this->dossier . '/' . $sousDossier, 0775, true);
        }

        file_put_contents($this->dossier . '/' . $sousDossier . '/' . $nomRange, $contenu);

        $precedents = $this->em->getRepository(Document::class)->findBy([
            'objetType' => $objetType,
            'objetId' => $objetId,
            'origine' => $origine,
            'archive' => false,
        ]);

        foreach ($precedents as $precedent) {
            $signe = array_any($precedent->getSignataires()->toArray(), static fn(Signataire $s) => $s->aSigne());

            if (!$signe) {
                $precedent->setArchive(true);
            }
        }

        $nom = mb_substr($nom, 0, 200);

        $document = new Document(
            $objetType,
            $objetId,
            $nom,
            $categorie,
            $sousDossier . '/' . $nomRange,
            // Nom de fichier proposé au téléchargement : sans les caractères interdits par Windows.
            preg_replace('#[\\\\/:*?"<>|]+#', ' ', $nom) . '.pdf',
            'application/pdf',
            strlen($contenu),
            hash('sha256', $contenu),
            $auteur?->getEmail(),
            $origine,
        );

        $this->em->persist($document);
        $this->em->flush();

        $this->consigner($document, '', 'Généré : ' . $document->getNom(), $auteur);

        return $document;
    }

    /** Le chemin du fichier sur le disque. */
    public function chemin(Document $document): string
    {
        return $this->dossier . '/' . $document->getFichier();
    }

    /**
     * Note qu'un document a été envoyé par email à son destinataire, et le
     * consigne au journal de l'objet auquel il est rattaché.
     */
    public function noterEnvoi(Document $document, string $email, ?User $auteur): void
    {
        $avant = $document->getEnvoyeA() === null
            ? $document->getNom() . ' : pas encore envoyé'
            : $document->getNom() . ' : envoyé à ' . $document->getEnvoyeA();

        $document->marquerEnvoye($email);
        $this->em->flush();

        $this->consigner(
            $document,
            $avant,
            $document->getNom() . ' : envoyé par email à ' . $email . ' le '
                . $document->getEnvoyeLe()->setTimezone(new \DateTimeZone('Europe/Paris'))->format('d/m/Y à H:i'),
            $auteur,
        );
    }

    public function archiver(Document $document, bool $archive, ?User $auteur): void
    {
        if ($document->isArchive() === $archive) {
            return;
        }

        $document->setArchive($archive);
        $this->em->flush();

        $this->consigner(
            $document,
            $document->getNom() . ($archive ? '' : ' (archivé)'),
            $document->getNom() . ($archive ? ' (archivé)' : ''),
            $auteur,
        );
    }

    /**
     * @throws DomainException
     */
    public function ajouterSignataire(Document $document, string $nom, string $email, ?User $auteur): Signataire
    {
        if (!$document->estPdf()) {
            throw new DomainException('Seul un PDF peut être envoyé pour signature.');
        }

        $nom = trim($nom);
        $email = mb_strtolower(trim($email));

        if ($nom === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new DomainException('Indiquez le nom et une adresse email valide du signataire.');
        }

        foreach ($document->getSignataires() as $existant) {
            if ($existant->getEmail() === $email) {
                throw new DomainException(sprintf('%s est déjà signataire de ce document.', $email));
            }
        }

        $signataire = new Signataire($document, mb_substr($nom, 0, 200), $email);
        $document->addSignataire($signataire);
        $this->em->flush();

        return $signataire;
    }

    /**
     * Retire un signataire qui n'a pas signé (demande annulée). Une
     * signature apposée, elle, ne se retire pas.
     *
     * @throws DomainException
     */
    public function retirerSignataire(Signataire $signataire, ?User $auteur): void
    {
        if ($signataire->aSigne()) {
            throw new DomainException(sprintf('%s a déjà signé : sa signature ne se retire pas.', $signataire->getNom()));
        }

        $document = $signataire->getDocument();
        $envoyee = $signataire->getEnvoyeLe() !== null;

        $document->removeSignataire($signataire);
        $this->em->flush();

        if ($envoyee) {
            $this->consigner(
                $document,
                sprintf('%s : signature demandée à %s', $document->getNom(), $signataire->getNom()),
                sprintf('%s : demande à %s annulée', $document->getNom(), $signataire->getNom()),
                $auteur,
            );
        }
    }

    /**
     * Enregistre les zones de signature placées dans l'admin. Les zones
     * d'un signataire dont la demande est partie sont ignorées : elles ne
     * changent plus.
     *
     * @param array<int|string, mixed> $parSignataire [idSignataire => liste de zones]
     */
    public function enregistrerZones(Document $document, array $parSignataire): void
    {
        foreach ($document->getSignataires() as $signataire) {
            if ($signataire->estVerrouille() || !array_key_exists($signataire->getId(), $parSignataire)) {
                continue;
            }

            $signataire->setZones($this->zonesValides($parSignataire[$signataire->getId()]));
        }

        $this->em->flush();
    }

    /**
     * Envoie (ou renvoie) à chaque signataire qui n'a pas encore signé son
     * lien personnel. Aucune demande ne part tant qu'un signataire n'a pas
     * sa zone : tous doivent pouvoir signer.
     *
     * @return array{envoyes: list<string>, echecs: list<string>}
     *
     * @throws DomainException
     */
    public function envoyer(Document $document, ?User $auteur): array
    {
        if ($document->isArchive()) {
            throw new DomainException('Ce document est archivé.');
        }

        $attendus = array_filter($document->getSignataires()->toArray(), static fn(Signataire $s) => !$s->aSigne());

        if ($attendus === []) {
            throw new DomainException('Aucune signature à demander.');
        }

        foreach ($attendus as $signataire) {
            if ($signataire->getZones() === []) {
                throw new DomainException(sprintf(
                    'Placez et enregistrez une zone de signature pour %s avant d\'envoyer.',
                    $signataire->getNom(),
                ));
            }
        }

        $envoyes = [];
        $echecs = [];

        foreach ($attendus as $signataire) {
            try {
                $this->mails->sendDemandeSignatureDocument(
                    $signataire->getEmail(),
                    $document->getNom(),
                    $this->urls->generate('app_signature', ['jeton' => $signataire->getJeton()], UrlGeneratorInterface::ABSOLUTE_URL),
                );

                $signataire->marquerEnvoye();
                $envoyes[] = $signataire->getNom();
            } catch (Throwable) {
                $echecs[] = $signataire->getNom();
            }
        }

        $this->em->flush();

        if ($envoyes !== []) {
            $this->consigner(
                $document,
                '',
                sprintf('%s : signature demandée à %s', $document->getNom(), implode(', ', $envoyes)),
                $auteur,
            );
        }

        return ['envoyes' => $envoyes, 'echecs' => $echecs];
    }

    /**
     * Le signataire appose sa signature depuis son lien personnel.
     *
     * @param string $image Le tracé, en data URL PNG.
     *
     * @throws DomainException
     */
    public function signer(Signataire $signataire, string $image, bool $approuve, ?string $ip, ?string $navigateur): void
    {
        if ($signataire->aSigne()) {
            throw new DomainException('Vous avez déjà signé ce document.');
        }

        if ($signataire->getZones() === []) {
            throw new DomainException("Ce document n'est pas encore prêt à être signé.");
        }

        if (!$approuve) {
            throw new DomainException('Cochez la case pour confirmer que vous avez lu et approuvé le document.');
        }

        if (strlen($image) > self::TRACE_MAX
            || strlen($image) < 200
            || preg_match('#^data:image/png;base64,[A-Za-z0-9+/]+=*$#', $image) !== 1) {
            throw new DomainException('Signez dans le cadre avant de valider.');
        }

        $signataire->signer($image, $ip, $navigateur);
        $this->em->flush();

        $document = $signataire->getDocument();

        $this->consigner(
            $document,
            '',
            sprintf(
                '%s : signé par %s%s',
                $document->getNom(),
                $signataire->getNom(),
                $document->etatSignature() === Document::SIGNE ? ' (toutes les signatures sont réunies)' : '',
            ),
            null,
        );
    }

    /**
     * Ne garde que des zones bien formées, dans la page.
     *
     * @return list<array{page: int, x: float, y: float, l: float, h: float}>
     */
    private function zonesValides(mixed $zones): array
    {
        $valides = [];

        foreach (is_array($zones) ? $zones : [] as $zone) {
            if (!is_array($zone) || count($valides) >= self::ZONES_MAX) {
                continue;
            }

            $page = (int)($zone['page'] ?? 0);
            $x = (float)($zone['x'] ?? -1);
            $y = (float)($zone['y'] ?? -1);
            $l = (float)($zone['l'] ?? 0);
            $h = (float)($zone['h'] ?? 0);

            if ($page < 1 || $x < 0 || $y < 0 || $l < 0.02 || $h < 0.01 || $x + $l > 1.001 || $y + $h > 1.001) {
                continue;
            }

            $valides[] = ['page' => $page, 'x' => round($x, 4), 'y' => round($y, 4), 'l' => round($l, 4), 'h' => round($h, 4)];
        }

        return $valides;
    }

    private function consigner(Document $document, string $avant, string $apres, ?User $auteur): void
    {
        $this->journal->modification(
            $document->getObjetType(),
            $document->getObjetId(),
            ['documents' => $avant],
            ['documents' => $apres],
            $auteur,
        );
    }
}
