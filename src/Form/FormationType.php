<?php

namespace App\Form;

use App\Entity\Formation;
use App\Enum\StatutFormation;
use App\Repository\FormationRepository;
use App\Service\CodesFormation;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\Validator\Constraints\Length;

/**
 * Fiche d'une formation, dans l'admin. Le gabarit range les champs par
 * section (admin/formation/form.html.twig).
 */
class FormationType extends AbstractType
{
    public function __construct(
        private readonly SluggerInterface $slugger,
        private readonly CodesFormation      $codes,
        private readonly FormationRepository $formations,
    )
    {
    }

    /**
     * L'adresse tirée de l'intitulé, suivie d'un numéro si une autre
     * formation l'a déjà : python-fondamentaux, python-fondamentaux-2…
     */
    private function slugLibre(string $nom): string
    {
        $base = mb_substr($this->slugger->slug($nom)->lower()->toString(), 0, 190);
        $slug = $base;

        for ($numero = 2; $this->formations->findOneBy(['slug' => $slug]) !== null; $numero++) {
            $slug = $base . '-' . $numero;
        }

        return $slug;
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $puces = 'Une ligne par point.';

        // On n'archive qu'une formation qui existe déjà : à la création, le
        // choix « Archivée » n'est pas proposé.
        $creation = $builder->getData()?->getId() === null;
        $statuts = array_values(array_filter(
            StatutFormation::cases(),
            static fn(StatutFormation $statut) => !$creation || $statut !== StatutFormation::Archivee,
        ));

        $builder
            ->add('nom', TextType::class, [
                'label' => 'Intitulé',
                'empty_data' => '',
            ])
            ->add('statut', EnumType::class, [
                'label' => 'Statut',
                'class' => StatutFormation::class,
                'choices' => $statuts,
                'choice_label' => static fn(StatutFormation $statut) => $statut->libelle(),
            ])
            ->add('domaine', TextType::class, [
                'label' => 'Domaine',
                'required' => false,
                'attr' => ['list' => 'domaines', 'autocomplete' => 'off'],
            ])
            ->add('accroche', TextareaType::class, [
                'label' => 'Accroche',
                'required' => false,
                'attr' => ['rows' => 2],
                'help' => 'Une phrase, affichée sur la carte du catalogue.',
            ])
            ->add('dureeHeures', NumberType::class, [
                'label' => 'Durée (heures)',
                'html5' => true,
                'attr' => ['min' => 0.5, 'step' => 0.5],
                'help' => '7 heures par journée.',
            ])
            ->add('lieu', TextType::class, [
                'label' => 'Lieu',
                'required' => false,
            ])
            ->add('tarifInterHT', MoneyType::class, [
                'label' => 'Tarif inter HT',
                'required' => false,
                'divisor' => 100,
                'input' => 'integer',
                'help' => "Prix d'une session, par participant.",
            ])
            ->add('tarifIntraHT', MoneyType::class, [
                'label' => 'Tarif intra HT',
                'required' => false,
                'divisor' => 100,
                'input' => 'integer',
                'help' => "Prix d'une session, pour le groupe.",
            ])
            ->add('objectifs', TextareaType::class, [
                'label' => 'Objectifs',
                'required' => false,
                'attr' => ['rows' => 7],
                'help' => $puces . " Un verbe d'action, un résultat évaluable.",
            ])
            ->add('publicVise', TextareaType::class, [
                'label' => 'Public visé',
                'required' => false,
                'attr' => ['rows' => 4],
                'help' => $puces,
            ])
            ->add('prerequis', TextareaType::class, [
                'label' => 'Prérequis',
                'required' => false,
                'attr' => ['rows' => 4],
                'help' => $puces,
            ])
            ->add('programme', TextareaType::class, [
                'label' => 'Programme',
                'required' => false,
                'attr' => [
                    'rows' => 22,
                    'placeholder' => "JOUR 1 – Titre de la journée\n◦ Séquence (2h)\n• Point abordé\n• Atelier : …",
                ],
                'help' => "« JOUR n – titre » (sauf formation d'un jour), « ◦ séquence (1h30) », « • point ».",
            ])
            ->add('ceQuiChange', TextareaType::class, [
                'label' => 'Ce que ça change, concrètement',
                'required' => false,
                'attr' => [
                    'rows' => 9,
                    'placeholder' => "◦ Situation de travail\nAujourd'hui : …\nAprès : …",
                ],
                'help' => 'Trois blocs : « ◦ situation », « Aujourd\'hui : … », « Après : … ».',
            ])
            ->add('methodes', TextareaType::class, [
                'label' => 'Méthodes pédagogiques',
                'required' => false,
                'attr' => ['rows' => 4],
                'help' => $puces,
            ])
            ->add('moyens', TextareaType::class, [
                'label' => 'Moyens et supports',
                'required' => false,
                'attr' => ['rows' => 4],
                'help' => $puces,
            ])
            ->add('evaluation', TextareaType::class, [
                'label' => "Modalités d'évaluation et de suivi",
                'required' => false,
                'attr' => ['rows' => 4],
                'help' => $puces,
            ])
            ->add('acces', TextareaType::class, [
                'label' => "Modalités et délais d'accès",
                'required' => false,
                'attr' => ['rows' => 4],
                'help' => $puces,
            ])
            ->add('accessibilite', TextareaType::class, [
                'label' => 'Accessibilité aux personnes en situation de handicap',
                'required' => false,
                'attr' => ['rows' => 4],
                'help' => $puces,
            ])
            ->add('profilFormateurs', TextareaType::class, [
                'label' => 'Profil des formateurs',
                'required' => false,
                'attr' => ['rows' => 4],
                'help' => $puces,
            ])
            ->add('note', NumberType::class, [
                'label' => 'Note de satisfaction (sur 5)',
                'required' => false,
                'html5' => true,
                'attr' => ['min' => 0, 'max' => 5, 'step' => 0.1],
                'help' => "Historique d'avant cet outil. Les réponses aux questionnaires à chaud s'y ajoutent sur le site.",
            ])
            ->add('nbFormes', IntegerType::class, [
                'label' => 'Personnes formées',
                'required' => false,
                'attr' => ['min' => 0],
                'help' => "Historique d'avant cet outil. Les participants émargés ici s'y ajoutent sur le site : ne plus l'augmenter à la main.",
            ]);

        if ($options['avec_motif']) {
            $builder->add('motif', TextType::class, [
                'label' => 'Raison de la modification',
                'mapped' => false,
                'required' => false,
                'constraints' => [new Length(max: 255)],
                'help' => "Facultatif. Conservée dans l'historique : « retours de la session de mars », « mise à jour vers la version 8 »…",
            ]);
        }

        // Avant la validation : l'adresse de la page et le code sont tirés de
        // l'intitulé à la création, puis ne changent plus (les liens diffusés
        // restent valables).
        $builder->addEventListener(FormEvents::SUBMIT, function (FormEvent $event): void {
            $formation = $event->getData();

            if (!$formation instanceof Formation || $formation->getNom() === '') {
                return;
            }

            if ($formation->getSlug() === '') {
                $formation->setSlug($this->slugLibre($formation->getNom()));
            }

            if ($formation->getCode() === '') {
                $formation->setCode($this->codes->generer($formation->getNom()));
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Formation::class,
            'avec_motif' => false,
        ]);

        $resolver->setAllowedTypes('avec_motif', 'bool');
    }
}
