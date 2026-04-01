<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

class RateLimiterSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private RateLimiterFactoryInterface $registerLimiter,
        private RateLimiterFactoryInterface $waitlistLimiter,
        private RateLimiterFactoryInterface $loginLimiter,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onRequest', 10],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) return;

        $request = $event->getRequest();
        $route   = $request->attributes->get('_route');
        $ip      = $request->getClientIp() ?? 'unknown';

        if ($route === 'app_register' && $request->isMethod('POST')) {
            if (!$this->registerLimiter->create($ip)->consume(1)->isAccepted()) {
                $event->setResponse(new Response('Trop de tentatives. Réessayez plus tard.', 429));
            }
        }

        if ($route === 'app_trading_waitlist' && $request->isMethod('POST')) {
            if (!$this->waitlistLimiter->create($ip)->consume(1)->isAccepted()) {
                $event->setResponse(new JsonResponse(['error' => 'Trop de requêtes.'], 429));
            }
        }

        if ($route === 'app_login' && $request->isMethod('POST')) {
            if (!$this->loginLimiter->create($ip)->consume(1)->isAccepted()) {
                $event->setResponse(new Response('Trop de tentatives. Réessayez plus tard.', 429));
            }
        }
    }
}