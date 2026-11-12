<?php

namespace App\Controller\Admin;

use App\Entity\Journalisable;
use App\Entity\User;
use App\Repository\EntreeJournalRepository;
use App\Service\Registre;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Liste, création, consultation et modification d'une fiche de gestion
 * (formateur, client, participant). Les trois se gèrent de la même façon :
 * chaque contrôleur ne décrit que ce qui lui est propre (colonnes de la
 * liste, champs de recherche, listes liées), les gabarits sont communs
 * (templates/admin/fiche).
 *
 * Il n'y a pas de suppression : une fiche s'archive, et garde son journal.
 *
 * Les routes sont déclarées ici ; chaque contrôleur fixe leur préfixe par
 * son attribut de classe (`/admin/formateurs`, `app_admin_formateur`).
 */
abstract class AbstractFicheController extends AbstractController
{
    public function __construct(
        protected readonly EntityManagerInterface  $em,
        protected readonly Registre                $registre,
        protected readonly EntreeJournalRepository $journal,
    )
    {
    }

    /** @return class-string<Journalisable> */
    abstract protected function classe(): string;

    /** @return class-string La classe du formulaire. */
    abstract protected function formulaire(): string;

    /** Le nom de route commun : « app_admin_formateur ». */
    abstract protected function route(): string;

    /**
     * @return array{pluriel: string, sousTitre: string, nouveau: string, modifier: string, cree: string, vide: string, recherche: string}
     */
    abstract protected function libelles(): array;

    /** @return list<string> Les champs sur lesquels porte la recherche : « f.nom ». L'alias est « f ». */
    abstract protected function champsRecherche(): array;

    /** @return array<string, string> Le tri de la liste : ['f.nom' => 'ASC']. */
    abstract protected function tri(): array;

    /** @return array<string, callable(Journalisable): string> Les colonnes de la liste, après le nom. */
    abstract protected function colonnes(): array;

    /**
     * Les listes liées affichées sur la fiche (sessions d'un formateur…).
     *
     * @return list<array{titre: string, vide: string, elements: list<array{texte: string, url: ?string, detail: string}>}>
     */
    protected function listes(Journalisable $fiche): array
    {
        return [];
    }

    /**
     * Les valeurs affichées sur la fiche. Par défaut, celles du journal.
     *
     * @return array<string, string|int|null>
     */
    protected function details(Journalisable $fiche): array
    {
        return $fiche->instantane();
    }

    /** La fiche vierge du formulaire de création, éventuellement préremplie d'après l'URL. */
    protected function nouvelleFiche(Request $request): Journalisable
    {
        return new ($this->classe())();
    }

    /** Où aller une fois la fiche créée. Par défaut, sur la fiche. */
    protected function apresCreation(Journalisable $fiche, Request $request): Response
    {
        return $this->redirectToRoute($this->route() . '_voir', ['id' => $fiche->getId()]);
    }

    /** Ajoute au tri ou à la recherche les jointures dont ils ont besoin. */
    protected function completerRequete(QueryBuilder $requete): void
    {
    }

    #[Route('', name: '', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $archives = $request->query->getBoolean('archives');
        $recherche = trim($request->query->getString('q'));

        $requete = $this->em->createQueryBuilder()
            ->select('f')
            ->from($this->classe(), 'f')
            ->where('f.archive = :archive')
            ->setParameter('archive', $archives);

        $this->completerRequete($requete);

        if ($recherche !== '') {
            $conditions = array_map(static fn(string $champ) => $champ . ' LIKE :recherche', $this->champsRecherche());
            $requete
                ->andWhere(implode(' OR ', $conditions))
                ->setParameter('recherche', '%' . addcslashes($recherche, '%_\\') . '%');
        }

        foreach ($this->tri() as $champ => $sens) {
            $requete->addOrderBy($champ, $sens);
        }

        $colonnes = $this->colonnes();
        $lignes = [];

        foreach ($requete->getQuery()->getResult() as $fiche) {
            $lignes[] = [
                'id' => $fiche->getId(),
                'nom' => $fiche->libelle(),
                'cellules' => array_map(static fn(callable $valeur) => $valeur($fiche), array_values($colonnes)),
            ];
        }

        return $this->render('admin/fiche/index.html.twig', [
            'libelles' => $this->libelles(),
            'route' => $this->route(),
            'colonnes' => array_keys($colonnes),
            'lignes' => $lignes,
            'archives' => $archives,
            'recherche' => $recherche,
            'nombres' => [
                'actifs' => $this->em->getRepository($this->classe())->count(['archive' => false]),
                'archives' => $this->em->getRepository($this->classe())->count(['archive' => true]),
            ],
        ]);
    }

    #[Route('/nouveau', name: '_nouveau', methods: ['GET', 'POST'])]
    public function nouveau(Request $request): Response
    {
        $fiche = $this->nouvelleFiche($request);

        $form = $this->createForm($this->formulaire(), $fiche, ['creation' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->registre->creer($fiche, $this->auteur());
            $this->addFlash('succes', $this->libelles()['cree']);

            return $this->apresCreation($fiche, $request);
        }

        return $this->render('admin/fiche/form.html.twig', [
            'libelles' => $this->libelles(),
            'route' => $this->route(),
            'fiche' => $fiche,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: '_voir', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function voir(int $id): Response
    {
        $fiche = $this->fiche($id);

        return $this->render('admin/fiche/voir.html.twig', [
            'libelles' => $this->libelles(),
            'route' => $this->route(),
            'fiche' => $fiche,
            'details' => $this->details($fiche),
            'listes' => $this->listes($fiche),
            'champs' => $fiche::champs(),
            'journal' => $this->journal->pour($fiche::typeJournal(), $fiche->getId()),
        ]);
    }

    #[Route('/{id}/modifier', name: '_modifier', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function modifier(int $id, Request $request): Response
    {
        $fiche = $this->fiche($id);
        $avant = $fiche->instantane();

        $form = $this->createForm($this->formulaire(), $fiche);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entree = $this->registre->modifier($fiche, $avant, $this->auteur(), $form->get('motif')->getData());

            $this->addFlash('succes', $entree === null
                ? 'Aucune modification à enregistrer.'
                : 'Modifications enregistrées.');

            return $this->redirectToRoute($this->route() . '_voir', ['id' => $fiche->getId()]);
        }

        return $this->render('admin/fiche/form.html.twig', [
            'libelles' => $this->libelles(),
            'route' => $this->route(),
            'fiche' => $fiche,
            'form' => $form,
        ]);
    }

    protected function fiche(int $id): Journalisable
    {
        $fiche = $this->em->find($this->classe(), $id);

        if ($fiche === null) {
            throw $this->createNotFoundException('Fiche introuvable.');
        }

        return $fiche;
    }

    protected function auteur(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }
}
