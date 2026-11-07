<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Uid\Uuid;

/** Assigns a request id (honouring a sane inbound X-Request-Id) and echoes it back. */
final class RequestContextSubscriber implements EventSubscriberInterface
{
    public const HEADER = 'X-Request-Id';

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onRequest', 255],
            KernelEvents::RESPONSE => ['onResponse', -255],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $incoming = $request->headers->get(self::HEADER);
        // only accept uuid-like ids: protects logs from injection / oversized values
        $id = \is_string($incoming) && 1 === preg_match('/^[A-Za-z0-9-]{8,36}$/', $incoming) ? $incoming : Uuid::v4()->toRfc4122();
        $request->attributes->set('request_id', $id);
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $id = $event->getRequest()->attributes->get('request_id');
        if (\is_string($id)) {
            $event->getResponse()->headers->set(self::HEADER, $id);
        }
    }
}
