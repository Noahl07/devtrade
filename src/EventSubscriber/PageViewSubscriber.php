<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\Analytics\PageViewRecorder;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Enregistre les pages vues après l'envoi de la réponse (kernel.terminate), pour ne pas ralentir l'affichage.
 * Les administrateurs ne sont pas comptés.
 */
final class PageViewSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly PageViewRecorder $recorder,
        private readonly Security $security,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::TERMINATE => 'onTerminate'];
    }

    public function onTerminate(TerminateEvent $event): void
    {
        $request = $event->getRequest();
        if (!$this->recorder->shouldRecord($request, $event->getResponse())) {
            return;
        }
        if ($this->security->isGranted('ROLE_ADMIN')) {
            return;
        }

        $this->recorder->record($request);
    }
}
