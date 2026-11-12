<?php

namespace App\Form;

use App\Entity\Client;
use App\Enum\TypeClient;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ClientType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, [
                'label' => 'Nom',
                'empty_data' => '',
                'help' => "Raison sociale, ou nom et prénom d'un particulier.",
            ])
            ->add('type', EnumType::class, [
                'label' => 'Type',
                'class' => TypeClient::class,
                'choice_label' => static fn(TypeClient $type) => $type->libelle(),
            ])
            ->add('siret', TextType::class, ['label' => 'SIRET', 'required' => false])
            ->add('adresse', TextType::class, ['label' => 'Adresse', 'required' => false])
            ->add('codePostal', TextType::class, ['label' => 'Code postal', 'required' => false])
            ->add('ville', TextType::class, ['label' => 'Ville', 'required' => false])
            ->add('contactNom', TextType::class, [
                'label' => 'Interlocuteur',
                'required' => false,
                'help' => 'La personne qui reçoit la convention et les convocations.',
            ])
            ->add('contactFonction', TextType::class, ['label' => "Fonction de l'interlocuteur", 'required' => false])
            ->add('contactEmail', EmailType::class, ['label' => "Email de l'interlocuteur", 'required' => false])
            ->add('contactTelephone', TelType::class, ['label' => "Téléphone de l'interlocuteur", 'required' => false])
            ->add('notes', TextareaType::class, [
                'label' => 'Notes internes',
                'required' => false,
                'attr' => ['rows' => 3],
            ]);

        FicheOptions::completer($builder, $options);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        FicheOptions::configurer($resolver, Client::class);
    }
}
