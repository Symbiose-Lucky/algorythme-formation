<?php

namespace App\Service;

use AllowDynamicProperties;
use App\Entity\Formation;
use App\Entity\SessionFormation;
use App\Enum\TypeEvaluation;
use Brevo\Brevo;
use Brevo\Exceptions\BrevoException;
use Brevo\TransactionalEmails\Requests\SendTransacEmailRequest;
use Brevo\TransactionalEmails\Types\SendTransacEmailRequestAttachmentItem;
use Brevo\TransactionalEmails\Types\SendTransacEmailRequestSender;
use Brevo\TransactionalEmails\Types\SendTransacEmailRequestToItem;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment as TwigEnvironment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

#[AllowDynamicProperties]
class MailService
{

    /**
     * @param string $destinataireSite boîte qui reçoit les demandes envoyées depuis le site
     *                                 (APP_DESTINATAIRE_EMAIL)
     */
    public function __construct(
        TwigEnvironment         $twig,
        UrlGeneratorInterface   $urlGenerator,
        LoggerInterface         $logger,
        private readonly string $destinataireSite,
    )
    {
        $this->twig = $twig;
        $this->urlGenerator = $urlGenerator;
        $this->logger = $logger;

    }

    /**
     * Envoi d'un email transactionnel via l'API Brevo.
     *
     * SDK getbrevo/brevo-php 5.x : l'ancienne API (Brevo\Client\Api\TransactionalEmailsApi
     * + Brevo\Client\Model\SendSmtpEmail + Configuration) a disparu en v5, remplacée par
     * le client généré Brevo\Brevo et ses objets de requête typés.
     *
     * @param array<UploadedFile|array{name: string, content: string}> $attachments Fichiers reçus d'un formulaire,
     *                                                                         ou pièces déjà en mémoire (nom, contenu binaire)
     *
     * @throws BrevoException si Brevo refuse ou n'accepte pas l'envoi
     */
    public function sendBrevoMail(string $sujet,
                                  string $destinataire,
                                  string $messageMail,
                                  array  $attachments = [],
                                  string $mailExpediteur = "contact@algorythme-formation.fr"): void
    {
        $brevo = new Brevo($_ENV['APP_BREVO_API_KEY']);

        if ($_ENV['APP_ENV'] == "dev" || $_ENV['APP_ENV'] == "DEV") {
            $destinataire = $_ENV['APP_DESTINATAIRE_DEV'];
        }

        $formattedAttachments = $this->piecesJointes($attachments);

        $params = [
            'subject' => $sujet,
            'sender' => new SendTransacEmailRequestSender([
                'email' => $mailExpediteur,
                'name' => 'Algorythme Formation',
            ]),
            'to' => [new SendTransacEmailRequestToItem(['email' => $destinataire])],
            'htmlContent' => $messageMail,
        ];

        if (!empty($formattedAttachments)) {
            $params['attachment'] = $formattedAttachments;
        }

        try {
            $brevo->transactionalEmails->sendTransacEmail(new SendTransacEmailRequest($params));
        } catch (BrevoException $e) {
            // On journalise puis on relance : l'appelant décide quoi montrer
            // à l'utilisateur (un `echo` ici polluait la réponse HTTP).
            $this->logger->error('Échec de l\'envoi d\'un email Brevo.', [
                'sujet' => $sujet,
                'destinataire' => $destinataire,
                'exception' => $e,
            ]);

            throw $e;
        }
    }

    /**
     * Met les pièces jointes au format attendu par Brevo (contenu en base64).
     *
     * @param array<UploadedFile|array{name: string, content: string}> $attachments
     *
     * @return list<SendTransacEmailRequestAttachmentItem>
     */
    private function piecesJointes(array $attachments): array
    {
        $pieces = [];

        foreach ($attachments as $file) {
            if ($file instanceof UploadedFile && $file->isValid()) {
                $pieces[] = new SendTransacEmailRequestAttachmentItem([
                    'name' => $file->getClientOriginalName(),
                    'content' => base64_encode(file_get_contents($file->getPathname())),
                ]);
            } elseif (is_array($file) && isset($file['name'], $file['content'])) {
                $pieces[] = new SendTransacEmailRequestAttachmentItem([
                    'name' => $file['name'],
                    'content' => base64_encode($file['content']),
                ]);
            }
        }

        return $pieces;
    }

    /**
     * Envoie à un participant un document de sa session, en pièce jointe :
     * convocation, certificat de réalisation, attestation de fin de formation.
     *
     * @param string $modele  « convocations », « certificats » ou « attestations »
     * @param string $contenu Le fichier PDF
     *
     * @throws BrevoException
     * @throws LoaderError
     * @throws RuntimeError
     * @throws SyntaxError
     */
    public function sendDocumentSession(string $destinataire, SessionFormation $session, string $modele, string $nomFichier, string $contenu): void
    {
        $nom = $session->getFormation()->getNom();
        $formation = htmlspecialchars($nom);

        [$sujet, $texte] = match ($modele) {
            'convocations' => [
                'Votre convocation - ' . $nom,
                "<p>Vous êtes inscrit(e) à la formation <strong>" . $formation . "</strong>. Vous trouverez
                    votre convocation en pièce jointe : elle précise les dates, les horaires et le lieu, ainsi
                    que votre lien personnel pour signer votre présence à chaque demi-journée.</p>",
            ],
            'certificats' => [
                'Votre certificat de réalisation - ' . $nom,
                "<p>Vous avez suivi la formation <strong>" . $formation . "</strong>. Vous trouverez en pièce
                    jointe votre certificat de réalisation. Conservez-le : il atteste de votre participation.</p>",
            ],
            'attestations' => [
                'Votre attestation de fin de formation - ' . $nom,
                "<p>Vous avez suivi la formation <strong>" . $formation . "</strong>. Vous trouverez en pièce
                    jointe votre attestation de fin de formation, avec le résultat de l'évaluation de vos
                    acquis. Conservez-la.</p>",
            ],
        };

        $message = $this->twig->render('emails/simple_message.html.twig', [
            'sujet' => $sujet,
            'message' => 'Bonjour,' . $texte . '<p>Pour toute question, répondez simplement à cet email.</p>',
        ]);

        $this->sendBrevoMail($sujet, $destinataire, $message, [['name' => $nomFichier, 'content' => $contenu]]);
    }

    /**
     * Demande de rappel envoyée depuis le quiz de cadrage de la page d'accueil.
     *
     * @param array<string, string> $contact nom, poste, email, tel
     * @param array<string, string> $reponses libellé de la question => réponse choisie
     *
     * @throws BrevoException
     * @throws LoaderError
     * @throws RuntimeError
     * @throws SyntaxError
     */
    public function sendDemandeRappel(array $contact, array $reponses): void
    {
        $sujet = 'Demande de rappel - ' . $contact['nom'] . ' (' . $contact['poste'] . ')';

        $message = $this->twig->render('emails/demande_rappel.html.twig', [
            'sujet' => $sujet,
            'contact' => $contact,
            'reponses' => $reponses,
        ]);

        $this->sendBrevoMail($sujet, $this->destinataireSite, $message);
    }

    /**
     * Demande envoyée depuis le formulaire de la page Contact.
     *
     * @param array<string, string> $contact nom, poste, entreprise, email, tel, message
     *
     * @throws BrevoException
     * @throws LoaderError
     * @throws RuntimeError
     * @throws SyntaxError
     */
    public function sendContact(array $contact): void
    {
        $sujet = 'Demande de contact - ' . $contact['nom'] . ' (' . $contact['entreprise'] . ')';

        $message = $this->twig->render('emails/contact.html.twig', [
            'sujet' => $sujet,
            'contact' => $contact,
        ]);

        $this->sendBrevoMail($sujet, $this->destinataireSite, $message);
    }

    /**
     * Envoie à un participant ou à un formateur son lien personnel
     * d'émargement pour une session.
     *
     * @throws BrevoException
     * @throws LoaderError
     * @throws RuntimeError
     * @throws SyntaxError
     */
    public function sendLienEmargement(string $destinataire, SessionFormation $session, string $lien): void
    {
        $formation = htmlspecialchars($session->getFormation()->getNom());
        $sujet = 'Votre feuille de présence - ' . $session->getFormation()->getNom();

        $message = $this->twig->render('emails/simple_message.html.twig', [
            'sujet' => $sujet,
            'message' => "Bonjour,
                    <p>Voici votre lien personnel pour signer votre présence à la formation
                    <strong>" . $formation . "</strong> (session " . $session->getReference() . ").</p>
                    <p>Ouvrez-le à chaque demi-journée, à partir de l'heure de début : vous signez
                    directement sur l'écran, sans compte ni mot de passe. Ce lien vous est propre,
                    ne le transmettez pas.</p>",
            'boutonText' => 'Signer ma présence',
            'boutonUrl' => $lien,
        ]);

        $this->sendBrevoMail($sujet, $destinataire, $message);
    }

    /**
     * Envoie à un participant le lien d'un questionnaire (positionnement,
     * satisfaction), ou à un formateur celui de l'évaluation des acquis.
     *
     * @throws BrevoException
     * @throws LoaderError
     * @throws RuntimeError
     * @throws SyntaxError
     */
    public function sendLienQuestionnaire(string $destinataire, SessionFormation $session, TypeEvaluation $type, string $lien): void
    {
        $formation = htmlspecialchars($session->getFormation()->getNom());

        [$sujet, $texte, $bouton] = match ($type) {
            TypeEvaluation::Positionnement => [
                'Avant votre formation - ' . $session->getFormation()->getNom(),
                "<p>Vous allez suivre la formation <strong>" . $formation . "</strong>. Pour que le formateur
                    l'adapte à votre niveau et à vos attentes, merci de répondre à ce court questionnaire
                    avant le début de la formation. Cela prend deux minutes.</p>",
                'Répondre au questionnaire',
            ],
            TypeEvaluation::Chaud => [
                'Votre avis sur la formation - ' . $session->getFormation()->getNom(),
                "<p>Vous venez de suivre la formation <strong>" . $formation . "</strong>. Votre avis nous
                    aide à l'améliorer : merci de prendre deux minutes pour répondre à ce questionnaire.</p>",
                'Donner mon avis',
            ],
            TypeEvaluation::Froid => [
                'Quelques semaines après votre formation - ' . $session->getFormation()->getNom(),
                "<p>Vous avez suivi la formation <strong>" . $formation . "</strong> il y a quelques semaines.
                    Avez-vous pu mettre en pratique ce que vous avez appris ? Merci de prendre deux minutes
                    pour nous le dire.</p>",
                'Faire le bilan',
            ],
            TypeEvaluation::Client => [
                'Votre avis sur la formation - ' . $session->getFormation()->getNom(),
                "<p>Vos collaborateurs viennent de suivre la formation <strong>" . $formation . "</strong>
                    (session " . $session->getReference() . "). Votre avis nous aide à améliorer nos formations :
                    merci de prendre deux minutes pour répondre à ce questionnaire.</p>",
                'Donner mon avis',
            ],
            TypeEvaluation::Financeur => [
                'Votre avis sur la formation - ' . $session->getFormation()->getNom(),
                "<p>Vous avez financé la formation <strong>" . $formation . "</strong> (session "
                    . $session->getReference() . "), qui vient de se terminer. Votre avis sur nos échanges et
                    sur les justificatifs transmis nous aide à nous améliorer : merci de prendre deux minutes
                    pour répondre à ce questionnaire.</p>",
                'Donner mon avis',
            ],
            TypeEvaluation::Formateur => [
                'Votre bilan de la session - ' . $session->getFormation()->getNom(),
                "<p>Vous venez d'animer la formation <strong>" . $formation . "</strong> (session "
                    . $session->getReference() . "). Merci de nous dire en deux minutes comment elle s'est
                    passée : préparation, conditions matérielles, groupe, programme.</p>",
                'Faire le bilan',
            ],
            TypeEvaluation::Acquis => [
                'Évaluation des acquis - ' . $session->getFormation()->getNom(),
                "<p>La formation <strong>" . $formation . "</strong> (session " . $session->getReference() . ")
                    se termine. Merci d'indiquer, pour chaque participant, les objectifs acquis : votre
                    évaluation figure sur l'attestation de fin de formation.</p>",
                'Évaluer les acquis',
            ],
        };

        $message = $this->twig->render('emails/simple_message.html.twig', [
            'sujet' => $sujet,
            'message' => 'Bonjour,' . $texte . '<p>Ce lien vous est propre, ne le transmettez pas.</p>',
            'boutonText' => $bouton,
            'boutonUrl' => $lien,
        ]);

        $this->sendBrevoMail($sujet, $destinataire, $message);
    }

    /**
     * Envoie à un formateur le lien de son espace personnel, où il retrouve
     * toutes ses sessions.
     *
     * @throws BrevoException
     * @throws LoaderError
     * @throws RuntimeError
     * @throws SyntaxError
     */
    public function sendLienEspaceFormateur(string $destinataire, string $lien): void
    {
        $sujet = 'Votre espace formateur - Algorythme Formation';

        $message = $this->twig->render('emails/simple_message.html.twig', [
            'sujet' => $sujet,
            'message' => "Bonjour,
                    <p>Voici le lien de votre espace formateur. Vous y retrouvez vos sessions à venir et
                    passées, avec pour chacune les dates, le groupe, le positionnement des participants,
                    le programme, votre feuille de présence, l'évaluation des acquis et votre bilan.</p>
                    <p>Ce lien vous est propre et reste valable : gardez-le, ne le transmettez pas.
                    Il s'ouvre sans compte ni mot de passe.</p>",
            'boutonText' => 'Ouvrir mon espace',
            'boutonUrl' => $lien,
        ]);

        $this->sendBrevoMail($sujet, $destinataire, $message);
    }

    /**
     * Demande à une personne de signer un document, par son lien personnel.
     *
     * @throws BrevoException
     * @throws LoaderError
     * @throws RuntimeError
     * @throws SyntaxError
     */
    public function sendDemandeSignatureDocument(string $destinataire, string $nomDocument, string $lien): void
    {
        $sujet = 'Document à signer - ' . $nomDocument;

        $message = $this->twig->render('emails/simple_message.html.twig', [
            'sujet' => $sujet,
            'message' => "Bonjour,
                    <p>Le document <strong>" . htmlspecialchars($nomDocument) . "</strong> attend votre signature.</p>
                    <p>Le lien ci-dessous vous permet de le lire puis de le signer directement à l'écran,
                    sans compte ni mot de passe. Il vous est propre : ne le transmettez pas.
                    Vous y retrouverez ensuite le document signé.</p>",
            'boutonText' => 'Lire et signer le document',
            'boutonUrl' => $lien,
        ]);

        $this->sendBrevoMail($sujet, $destinataire, $message);
    }

    /*
     * Envoi un mail pour dire au formateur de la formation d'aller voir le message sur la formation
     */
    /**
     * @throws RuntimeError
     * @throws SyntaxError
     * @throws LoaderError
     */
    public function sendNotifConversationByEmail(Formation $formation): void
    {

        $sujet = "Nouveau message pour la formation " . $formation->getFormationPattern()->getNom();
        $message = $this->twig->render('emails/simple_message.html.twig', [
            'sujet' => $sujet,
            'message' => "Bonjour,
                    <p>vous venez de recevoir un message sur Eluv à propos de la formation <strong>" . $formation->getFormationPattern()->getNom() . "</strong></p>",
            'boutonText' => 'Voir le message',
            'boutonUrl' => $this->urlGenerator->generate('app_conversation_par_formation', ['idFormation' => $formation->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
        ]);

        $this->sendBrevoMail($sujet, $formation->getFormateur()->getUser()->getEmail(), $message);

    }

    /*
     * Envoi un mail au formateur pour lui demander de signer électroniquement sa demande d'intervention
     */
    /**
     * @throws RuntimeError
     * @throws SyntaxError
     * @throws LoaderError
     */
    public function sendDemandeSignature(Formation $formation): void
    {

        $formateur = $formation->getSignatureFormateur();

        $sujet = "Demande d'intervention à signer pour la formation " . $formation->getFormationPattern()->getNom();
        $message = $this->twig->render('emails/simple_message.html.twig', [
            'sujet' => $sujet,
            'message' => "Bonjour,
                    <p>Une demande d'intervention pour la formation <strong>" . $formation->getFormationPattern()->getNom() . "</strong> est prête à être signée.</p>",
            'boutonText' => 'Consulter et signer la demande',
            'boutonUrl' => $this->urlGenerator->generate('app_signature_voir', ['token' => $formation->getSignatureToken()], UrlGeneratorInterface::ABSOLUTE_URL),
        ]);

        $this->sendBrevoMail($sujet, $formateur->getUser()->getEmail(), $message);

    }

    /*
     * Relance le formateur qui n'a pas encore signé sa demande d'intervention
     */
    /**
     * @throws RuntimeError
     * @throws SyntaxError
     * @throws LoaderError
     */
    public function sendRelanceSignature(Formation $formation): void
    {

        $formateur = $formation->getSignatureFormateur();

        $sujet = "Rappel : demande d'intervention à signer pour la formation " . $formation->getFormationPattern()->getNom();
        $message = $this->twig->render('emails/simple_message.html.twig', [
            'sujet' => $sujet,
            'message' => "Bonjour,
                    <p>Petit rappel : la demande d'intervention pour la formation <strong>" . $formation->getFormationPattern()->getNom() . "</strong>
                    est toujours en attente de votre signature.</p>",
            'boutonText' => 'Consulter et signer la demande',
            'boutonUrl' => $this->urlGenerator->generate('app_signature_voir', ['token' => $formation->getSignatureToken()], UrlGeneratorInterface::ABSOLUTE_URL),
        ]);

        $this->sendBrevoMail($sujet, $formateur->getUser()->getEmail(), $message);

    }

    /*
     * Prévient le formateur que sa demande d'intervention a été supprimée (annulée)
     */
    /**
     * @throws RuntimeError
     * @throws SyntaxError
     * @throws LoaderError
     */
    public function sendAnnulationDemandeSignature(Formation $formation): void
    {

        $formateur = $formation->getSignatureFormateur();

        $sujet = "Demande d'intervention annulée pour la formation " . $formation->getFormationPattern()->getNom();
        $message = $this->twig->render('emails/simple_message.html.twig', [
            'sujet' => $sujet,
            'message' => "Bonjour,
                    <p>La demande d'intervention qui vous avait été envoyée pour la formation <strong>" . $formation->getFormationPattern()->getNom() . "</strong>
                    a été annulée. Le lien de signature n'est donc plus valable.</p>
                    <p>N'hésitez pas à nous contacter si vous avez des questions.</p>",
        ]);

        $this->sendBrevoMail($sujet, $formateur->getUser()->getEmail(), $message);

    }

    /*
     * Envoi un mail au destinataire du devis pour lui demander de le signer électroniquement
     */
    /**
     * @throws RuntimeError
     * @throws SyntaxError
     * @throws LoaderError
     */
    public function sendDevisSignature(Formation $formation): void
    {

        $sujet = "Devis à signer pour la formation " . $formation->getFormationPattern()->getNom();
        $message = $this->twig->render('emails/simple_message.html.twig', [
            'sujet' => $sujet,
            'message' => "Bonjour,
                    <p>Un devis pour la formation <strong>" . $formation->getFormationPattern()->getNom() . "</strong> est prêt à être signé.</p>",
            'boutonText' => 'Consulter et signer le devis',
            'boutonUrl' => $this->urlGenerator->generate('app_devis_voir', ['token' => $formation->getDevisToken()], UrlGeneratorInterface::ABSOLUTE_URL),
        ]);

        $this->sendBrevoMail($sujet, $formation->getDevisEmail(), $message);

    }

    /*
     * Relance le destinataire du devis qui ne l'a pas encore signé
     */
    /**
     * @throws RuntimeError
     * @throws SyntaxError
     * @throws LoaderError
     */
    public function sendRelanceDevisSignature(Formation $formation): void
    {

        $sujet = "Rappel : devis à signer pour la formation " . $formation->getFormationPattern()->getNom();
        $message = $this->twig->render('emails/simple_message.html.twig', [
            'sujet' => $sujet,
            'message' => "Bonjour,
                    <p>Petit rappel : le devis pour la formation <strong>" . $formation->getFormationPattern()->getNom() . "</strong>
                    est toujours en attente de votre signature.</p>",
            'boutonText' => 'Consulter et signer le devis',
            'boutonUrl' => $this->urlGenerator->generate('app_devis_voir', ['token' => $formation->getDevisToken()], UrlGeneratorInterface::ABSOLUTE_URL),
        ]);

        $this->sendBrevoMail($sujet, $formation->getDevisEmail(), $message);

    }

    /*
     * Envoi un mail de confirmation au destinataire du devis une fois celui-ci signé,
     * avec un lien pour retrouver le PDF signé si besoin
     */
    /**
     * @throws RuntimeError
     * @throws SyntaxError
     * @throws LoaderError
     */
    public function sendDevisSignee(Formation $formation): void
    {

        $sujet = "Votre devis signé pour la formation " . $formation->getFormationPattern()->getNom();
        $message = $this->twig->render('emails/simple_message.html.twig', [
            'sujet' => $sujet,
            'message' => "Bonjour,
                    <p>Le devis pour la formation <strong>" . $formation->getFormationPattern()->getNom() . "</strong> a bien été signé.</p>
                    <p>Vous pouvez le retrouver à tout moment via le lien ci-dessous.</p>",
            'boutonText' => 'Retrouver le devis signé',
            'boutonUrl' => $this->urlGenerator->generate('app_devis_voir', ['token' => $formation->getDevisToken()], UrlGeneratorInterface::ABSOLUTE_URL),
        ]);

        $this->sendBrevoMail($sujet, $formation->getDevisEmail(), $message);

    }

    /*
     * Prévient le destinataire du devis que celui-ci a été supprimé (annulé)
     */
    /**
     * @throws RuntimeError
     * @throws SyntaxError
     * @throws LoaderError
     */
    public function sendAnnulationDevis(Formation $formation): void
    {

        $sujet = "Devis annulé pour la formation " . $formation->getFormationPattern()->getNom();
        $message = $this->twig->render('emails/simple_message.html.twig', [
            'sujet' => $sujet,
            'message' => "Bonjour,
                    <p>Le devis qui vous avait été envoyé pour la formation <strong>" . $formation->getFormationPattern()->getNom() . "</strong>
                    a été annulé. Le lien de signature n'est donc plus valable.</p>
                    <p>N'hésitez pas à nous contacter si vous avez des questions.</p>",
        ]);

        $this->sendBrevoMail($sujet, $formation->getDevisEmail(), $message);

    }

}
