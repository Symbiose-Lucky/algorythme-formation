<?php

namespace App\Form;

use App\Entity\Organisme;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class OrganismeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, ['label' => 'Raison sociale', 'empty_data' => ''])
            ->add('formeJuridique', TextType::class, [
                'label' => 'Forme juridique',
                'required' => false,
                'help' => 'Exemple : SAS au capital de 1 000 euros.',
            ])
            ->add('adresse', TextType::class, ['label' => 'Adresse', 'required' => false])
            ->add('codePostal', TextType::class, ['label' => 'Code postal', 'required' => false])
            ->add('ville', TextType::class, ['label' => 'Ville', 'required' => false])
            ->add('siret', TextType::class, ['label' => 'SIRET', 'required' => false])
            ->add('rcs', TextType::class, ['label' => 'RCS', 'required' => false])
            ->add('nda', TextType::class, [
                'label' => "Numéro de déclaration d'activité",
                'required' => false,
            ])
            ->add('ndaRegion', TextType::class, [
                'label' => 'Région de la déclaration',
                'required' => false,
                'help' => "La région dont le préfet a enregistré la déclaration d'activité.",
            ])
            ->add('telephone', TelType::class, ['label' => 'Téléphone', 'required' => false])
            ->add('email', EmailType::class, ['label' => 'Email', 'required' => false])
            ->add('representantNom', TextType::class, [
                'label' => 'Représentant légal',
                'required' => false,
                'help' => 'La personne qui signe les conventions et les certificats.',
            ])
            ->add('representantQualite', TextType::class, [
                'label' => 'Qualité du représentant',
                'required' => false,
                'help' => 'Exemple : Président.',
            ])
            ->add('tauxTva', NumberType::class, [
                'label' => 'Taux de TVA (%)',
                'required' => false,
                'html5' => true,
                'attr' => ['min' => 0, 'max' => 30, 'step' => 0.1],
                'help' => "0 si l'organisme est exonéré (article 261-4-4° a du CGI), 20 sinon.",
            ])
            ->add('conditionsReglement', TextareaType::class, [
                'label' => 'Conditions de règlement',
                'required' => false,
                'attr' => ['rows' => 3],
                'help' => 'Reprises telles quelles dans la convention.',
            ])
            ->add('conditionsAnnulation', TextareaType::class, [
                'label' => "Conditions d'annulation et de report",
                'required' => false,
                'attr' => ['rows' => 4],
                'help' => "Reprises telles quelles dans la convention. Laissées vides, seul le rappel légal (article L. 6354-1 du Code du travail) y figure.",
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Organisme::class]);
    }
}
