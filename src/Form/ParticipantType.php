<?php

namespace App\Form;

use App\Entity\Client;
use App\Entity\Participant;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ParticipantType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Participant|null $participant */
        $participant = $options['data'] ?? null;
        $clientActuel = $participant?->getClient();

        $builder
            ->add('prenom', TextType::class, ['label' => 'Prénom', 'empty_data' => ''])
            ->add('nom', TextType::class, ['label' => 'Nom', 'empty_data' => ''])
            ->add('email', EmailType::class, ['label' => 'Email', 'required' => false])
            ->add('telephone', TelType::class, ['label' => 'Téléphone', 'required' => false])
            ->add('client', EntityType::class, [
                'label' => 'Client',
                'class' => Client::class,
                'required' => false,
                'placeholder' => 'Aucun (particulier)',
                'choice_label' => static fn(Client $client) => $client->getNom(),
                // Les clients archivés ne sont plus proposés, sauf celui de la fiche.
                'query_builder' => static function (EntityRepository $clients) use ($clientActuel) {
                    $requete = $clients->createQueryBuilder('c')->where('c.archive = false')->orderBy('c.nom', 'ASC');

                    if ($clientActuel?->getId() !== null) {
                        $requete->orWhere('c = :actuel')->setParameter('actuel', $clientActuel);
                    }

                    return $requete;
                },
            ])
            ->add('fonction', TextType::class, ['label' => 'Fonction', 'required' => false])
            ->add('amenagements', TextareaType::class, [
                'label' => 'Aménagements à prévoir',
                'required' => false,
                'attr' => ['rows' => 3],
                'help' => "Situation de handicap ou contrainte particulière. Donnée sensible : n'écrire que ce qui est utile à l'organisation de la formation.",
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
        FicheOptions::configurer($resolver, Participant::class);
    }
}
