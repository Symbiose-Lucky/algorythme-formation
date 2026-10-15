<?php

declare(strict_types=1);

namespace App\Monitoring;

use Sentry\Event;
use Sentry\EventHint;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Filtre appliqué avant chaque envoi d'événement à Sentry (option `before_send`).
 * Renvoyer null annule l'envoi.
 */
final class SentryFilter
{
    public static function beforeSend(Event $event, ?EventHint $hint = null): ?Event
    {
        $exception = $hint?->exception;

        while ($exception instanceof \Throwable) {
            // Erreurs HTTP 4xx (404, 403, 405, 422…) : réponses normales, pas des bugs.
            if ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() < 500) {
                return null;
            }

            if ($exception instanceof \ErrorException) {
                $message = $exception->getMessage();

                // Fichier du cache absent pendant un déploiement (cache en cours de régénération).
                if (str_contains($message, '/var/cache/') && str_contains($message, 'Failed to open stream')) {
                    return null;
                }

                // Dépréciations PHP : à traiter lors des montées de version, pas en alerte de prod.
                if (in_array($exception->getSeverity(), [E_DEPRECATED, E_USER_DEPRECATED], true)) {
                    return null;
                }
            }

            $exception = $exception->getPrevious();
        }

        return $event;
    }
}
