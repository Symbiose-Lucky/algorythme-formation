<?php

declare(strict_types=1);

namespace App\Monitoring;

use Sentry\Event;
use Sentry\EventHint;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Filtre appliqué avant chaque envoi d'événement à Sentry (option `before_send`).
 *
 * Signature attendue par sentry/sentry 4.x : callable(Event, ?EventHint): ?Event.
 * Renvoyer null annule l'envoi.
 */
final class SentryFilter
{
    public static function beforeSend(Event $event, ?EventHint $hint = null): ?Event
    {
        $exception = $hint?->exception;

        // Les erreurs HTTP 4xx (404, 403, 405…) ne sont pas des bugs : on ne les remonte pas,
        // même lorsqu'elles sont enveloppées dans une autre exception.
        while ($exception instanceof \Throwable) {
            if ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() < 500) {
                return null;
            }

            $exception = $exception->getPrevious();
        }

        return $event;
    }
}
