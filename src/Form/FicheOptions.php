<?php

namespace App\Form;

use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;

/**
 * Ce que les formulaires des fiches de gestion (formateur, client,
 * participant) ont en commun : l'option `creation`, et, en modification,
 * la case « Archivé » et la raison du changement.
 */
final class FicheOptions
{
    public static function configurer(OptionsResolver $resolver, string $classe): void
    {
        $resolver->setDefaults([
            'data_class' => $classe,
            'creation' => false,
        ]);

        $resolver->setAllowedTypes('creation', 'bool');
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function completer(FormBuilderInterface $builder, array $options): void
    {
        if ($options['creation']) {
            return;
        }

        $builder
            ->add('archive', CheckboxType::class, [
                'label' => 'Archiver cette fiche',
                'required' => false,
                'help' => "Une fiche archivée n'est plus proposée dans les listes de choix. Rien n'est effacé.",
            ])
            ->add('motif', TextType::class, [
                'label' => 'Raison de la modification',
                'mapped' => false,
                'required' => false,
                'constraints' => [new Length(max: 255)],
                'help' => 'Facultatif. Conservée dans le journal.',
            ]);
    }
}
