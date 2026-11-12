<?php

namespace App\Form;

use App\Entity\Formateur;
use App\Entity\Formation;
use App\Enum\StatutFormateur;
use App\Enum\StatutFormation;
use App\Repository\FormationRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class FormateurType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('prenom', TextType::class, ['label' => 'Prénom', 'empty_data' => ''])
            ->add('nom', TextType::class, ['label' => 'Nom', 'empty_data' => ''])
            ->add('email', EmailType::class, ['label' => 'Email', 'required' => false])
            ->add('telephone', TelType::class, ['label' => 'Téléphone', 'required' => false])
            ->add('statut', EnumType::class, [
                'label' => 'Statut',
                'class' => StatutFormateur::class,
                'choice_label' => static fn(StatutFormateur $statut) => $statut->libelle(),
            ])
            ->add('societe', TextType::class, [
                'label' => 'Société',
                'required' => false,
                'help' => 'Pour un indépendant.',
            ])
            ->add('siret', TextType::class, ['label' => 'SIRET', 'required' => false])
            ->add('nda', TextType::class, [
                'label' => "Numéro de déclaration d'activité",
                'required' => false,
                'help' => "Pour un sous-traitant qui est lui-même organisme de formation.",
            ])
            ->add('specialites', TextareaType::class, [
                'label' => 'Domaines de compétence',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('parcours', TextareaType::class, [
                'label' => 'Parcours',
                'required' => false,
                'attr' => ['rows' => 6],
                'help' => 'Diplômes, certifications, expérience : ce qui fonde sa compétence à former.',
            ])
            ->add('formations', EntityType::class, [
                'label' => 'Formations animées',
                'class' => Formation::class,
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'by_reference' => false,
                'choice_label' => static fn(Formation $formation) => $formation->getNom(),
                'query_builder' => static fn(FormationRepository $formations) => $formations
                    ->createQueryBuilder('f')
                    ->where('f.statut != :archivee')
                    ->setParameter('archivee', StatutFormation::Archivee)
                    ->orderBy('f.nom', 'ASC'),
            ])
            ->add('notes', TextareaType::class, [
                'label' => 'Notes internes',
                'required' => false,
                'attr' => ['rows' => 3],
            ]);

        FicheOptions::completer($builder, $options);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        FicheOptions::configurer($resolver, Formateur::class);
    }
}
