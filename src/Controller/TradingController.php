<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\ShiroCalendar;
use App\Service\WaitlistRegistration;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class TradingController extends AbstractController
{
    #[Route('/trading', name: 'app_trading', methods: ['GET'])]
    public function index(
        ShiroCalendar $calendar,
        #[Autowire('%shiro.backtests%')] array $backtests,
        #[Autowire('%shiro.costs_note%')] string $costsNote,
        #[Autowire('%shiro.demo_since%')] string $demoSince,
        #[Autowire('%shiro.symbol%')] string $symbol,
        #[Autowire('%kernel.project_dir%/public/data/shiro/resume.json')] string $summaryFile,
    ): Response {
        // Seules les séries complètes (contexte obligatoire renseigné) sont affichées.
        $published = array_values(array_filter(
            $backtests,
            static fn (array $b): bool => ($b['published'] ?? false) === true && !empty($b['trades'])
        ));

        // Résumé annuel des graphiques (généré par tools/shiro_charts.py), pour le tableau accessible.
        $summary = is_file($summaryFile) ? json_decode((string) file_get_contents($summaryFile), true) : null;

        return $this->render('trading/index.html.twig', [
            'backtests'  => $published,
            'costs_note' => $costsNote,
            'demo_since' => $demoSince,
            'symbol'     => $symbol,
            'calendar'   => $calendar->upcomingByMonth(),
            'summary'    => is_array($summary) ? $summary : null,
        ]);
    }

    #[Route('/trading/waitlist', name: 'app_trading_waitlist', methods: ['POST'])]
    public function waitlist(Request $request, WaitlistRegistration $registration): JsonResponse
    {
        if (!$this->isCsrfTokenValid('waitlist', (string) $request->request->get('_token'))) {
            return $this->json(['error' => 'Session expirée : rechargez la page puis réessayez.'], 400);
        }

        // Piège à robots : un humain ne voit pas ce champ. On répond « succès » sans rien enregistrer.
        if ((string) $request->request->get('website') !== '') {
            return $this->json(['success' => true, 'message' => 'Inscription enregistrée.']);
        }

        $error = $registration->register(
            (string) $request->request->get('email'),
            $request->request->get('firstName') !== null ? (string) $request->request->get('firstName') : null,
            $request->request->getBoolean('consent'),
            'shiro'
        );

        if ($error !== null) {
            return $this->json(['error' => $error], 400);
        }

        // Message identique que l'adresse soit nouvelle ou déjà inscrite (pas d'énumération).
        return $this->json(['success' => true, 'message' => 'Inscription enregistrée. Vous serez prévenu une seule fois, à la sortie.']);
    }
}
