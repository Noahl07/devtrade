<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactory;

class RateLimiterSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private \Symfony\Component\RateLimiter\RateLimiterFactory $registerLimiter,
        private \Symfony\Component\RateLimiter\RateLimiterFactory $waitlistLimiter,
        private \Symfony\Component\RateLimiter\RateLimiterFactory $loginLimiter,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onRequest', 10],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $route   = $request->attributes->get('_route');
        $ip      = $request->getClientIp() ?? 'unknown';

        // Rate limit sur l'inscription
        if ($route === 'app_register' && $request->isMethod('POST')) {
            $limiter = $this->registerLimiter->create($ip);
            if (!$limiter->consume(1)->isAccepted()) {
                $event->setResponse(new Response(
                    'Trop de tentatives. Réessayez dans quelques minutes.',
                    Response::HTTP_TOO_MANY_REQUESTS
                ));
            }
        }

        // Rate limit sur la waitlist
        if ($route === 'app_trading_waitlist' && $request->isMethod('POST')) {
            $limiter = $this->waitlistLimiter->create($ip);
            if (!$limiter->consume(1)->isAccepted()) {
                $event->setResponse(new JsonResponse(
                    ['error' => 'Trop de requêtes. Réessayez dans quelques minutes.'],
                    Response::HTTP_TOO_MANY_REQUESTS
                ));
            }
        }

        // Rate limit sur le login
        if ($route === 'app_login' && $request->isMethod('POST')) {
            $limiter = $this->loginLimiter->create($ip);
            if (!$limiter->consume(1)->isAccepted()) {
                $event->setResponse(new Response(
                    'Trop de tentatives de connexion. Réessayez dans quelques minutes.',
                    Response::HTTP_TOO_MANY_REQUESTS
                ));
            }
        }
    }
}
