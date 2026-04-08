<?php

namespace App\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class LocaleController extends AbstractController
{
    #[Route('/langue/{locale}', name: 'app_locale_switch', requirements: ['locale' => 'fr|en'], methods: ['GET'])]
    public function switchLocale(
        string $locale,
        Request $request,
        EntityManagerInterface $em
    ): Response {
        // 1. Toujours sauvegarder en session (fonctionne connecté ou non)
        $request->getSession()->set('_locale', $locale);

        // 2. Si l'user est connecté → persister en BDD
        $user = $this->getUser();
        if ($user && method_exists($user, 'setLocale')) {
            $user->setLocale($locale);
            $em->flush();
        }

        // 3. Retour sur la page précédente
        $referer = $request->headers->get('referer');
        if ($referer) {
            return $this->redirect($referer);
        }

        return $this->redirectToRoute('app_home');
    }
}