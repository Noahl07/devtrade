<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

class LocaleSubscriber implements EventSubscriberInterface
{
    public function __construct(private string $defaultLocale = 'fr') {}

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        if (method_exists($user, 'getLocale') && $user->getLocale()) {
            $event->getRequest()->getSession()->set('_locale', $user->getLocale());
        }
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        // Lire la locale depuis la session, fallback sur 'fr'
        $locale = $request->getSession()->get('_locale', $this->defaultLocale);

        // Appliquer sur la requête ET sur la session pour être sûr
        $request->setLocale($locale);
        $request->getSession()->set('_locale', $locale);
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST    => [['onKernelRequest', 20]],
            LoginSuccessEvent::class => 'onLoginSuccess',
        ];
    }
}