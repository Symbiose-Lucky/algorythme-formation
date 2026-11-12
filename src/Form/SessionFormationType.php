<?php

namespace App\Form;

use App\Entity\Client;
use App\Entity\Formateur;
use App\Entity\Formation;
use App\Entity\SessionFormation;
use App\Enum\FormuleSession;
use App\Enum\ModaliteSession;
use App\Enum\StatutFormation;
use App\Enum\StatutSession;
use App\Repository\FormationRepository;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;

/**
 * Fiche d'une session, dans l'admin. La formation se choisit à la
 * création et ne change plus ensuite : une session ne passe pas d'un
 * programme à un autre.
 */
class SessionFormationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if ($options['creation']) {
            $builder->add('formation', EntityType::class, [
                'label' => 'Formation',
                'class' => Formation::class,
                'placeholder' => 'Choisir une formation',
                'choice_label' => static fn(Formation $f) => sprintf('%s (%s)', $f->getNom(), $f->getCode()),
                'query_builder' => static fn(FormationRepository $formations) => $formations
                    ->createQueryBuilder('f')
                    ->where('f.statut != :archivee')
                    ->setParameter('archivee', StatutFormation::Archivee)
                    ->orderBy('f.nom', 'ASC'),
            ]);
        }

        $builder
            ->add('formule', EnumType::class, [
                'label' => 'Formule',
                'class' => FormuleSession::class,
                'choice_label' => static fn(FormuleSession $formule) => $formule->libelle(),
            ])
            ->add('modalite', EnumType::class, [
                'label' => 'Modalité',
                'class' => ModaliteSession::class,
                'choice_label' => static fn(ModaliteSession $modalite) => $modalite->libelle(),
            ])
            ->add('statut', EnumType::class, [
                'label' => 'Statut',
                'class' => StatutSession::class,
                'choice_label' => static fn(StatutSession $statut) => $statut->libelle(),
            ])
            ->add('client', EntityType::class, [
                'label' => 'Client',
                'class' => Client::class,
                'required' => false,
                'placeholder' => 'Aucun (session inter)',
                'choice_label' => static fn(Client $client) => $client->getNom(),
                'query_builder' => static fn(EntityRepository $clients) => $clients
                    ->createQueryBuilder('c')
                    ->where('c.archive = false')
                    ->orderBy('c.nom', 'ASC'),
                'help' => "Le commanditaire d'une session intra.",
            ])
            ->add('formateurs', EntityType::class, [
                'label' => 'Formateurs',
                'class' => Formateur::class,
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'by_reference' => false,
                'choice_label' => static fn(Formateur $formateur) => $formateur->libelle(),
                'query_builder' => static fn(EntityRepository $formateurs) => $formateurs
                    ->createQueryBuilder('f')
                    ->where('f.archive = false')
                    ->orderBy('f.nom', 'ASC')
                    ->addOrderBy('f.prenom', 'ASC'),
            ])
            ->add('lieu', TextType::class, [
                'label' => 'Lieu',
                'required' => false,
                'help' => 'Adresse de la salle, ou « classe virtuelle ».',
            ])
            ->add('effectifMax', IntegerType::class, [
                'label' => 'Effectif maximum',
                'required' => false,
                'attr' => ['min' => 1],
            ])
            ->add('notes', TextareaType::class, [
                'label' => 'Notes internes',
                'required' => false,
                'attr' => ['rows' => 3],
                'help' => "Visibles dans l'admin seulement.",
            ])
            ->add('seances', CollectionType::class, [
                'label' => false,
                'entry_type' => SeanceType::class,
                'allow_add' => true,
                'allow_delete' => true,
                // addSeance() / removeSeance() : chaque séance est rattachée à la session.
                'by_reference' => false,
                'error_bubbling' => false,
            ]);

        if (!$options['creation']) {
            $builder->add('motif', TextType::class, [
                'label' => 'Raison de la modification',
                'mapped' => false,
                'required' => false,
                'constraints' => [new Length(max: 255)],
                'help' => 'Facultatif. Conservée dans le journal : « report demandé par le client »…',
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SessionFormation::class,
            'creation' => false,
        ]);

        $resolver->setAllowedTypes('creation', 'bool');
    }
}
