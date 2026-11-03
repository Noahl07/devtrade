<?php

namespace App\Controller;

use App\Entity\WaitlistEntry;
use App\Repository\WaitlistEntryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class TradingController extends AbstractController
{
    #[Route('/trading', name: 'app_trading', methods: ['GET'])]
    public function index(
        #[Autowire('%shiro.backtests%')] array $backtests,
        #[Autowire('%shiro.costs_note%')] string $costsNote,
        #[Autowire('%shiro.demo_since%')] string $demoSince,
        #[Autowire('%shiro.symbol%')] string $symbol,
    ): Response {
        // Seules les séries complètes (contexte obligatoire renseigné) sont affichées.
        $published = array_values(array_filter(
            $backtests,
            static fn (array $b): bool => ($b['published'] ?? false) === true && !empty($b['trades'])
        ));

        return $this->render('trading/index.html.twig', [
            'backtests'  => $published,
            'costs_note' => $costsNote,
            'demo_since' => $demoSince,
            'symbol'     => $symbol,
        ]);
    }

    #[Route('/trading/waitlist', name: 'app_trading_waitlist', methods: ['POST'])]
    public function waitlist(
        Request $request,
        EntityManagerInterface $em,
        WaitlistEntryRepository $waitlistRepo,
        ValidatorInterface $validator
    ): JsonResponse {
        // Accepte JSON ou form data
        $data = [];
        $contentType = $request->headers->get('Content-Type', '');

        if (str_contains($contentType, 'application/json')) {
            $data = json_decode($request->getContent(), true) ?? [];
        } else {
            $data = $request->request->all();
        }

        $email     = trim($data['email'] ?? '');
        $firstName = trim($data['firstName'] ?? '');
        $source    = trim($data['source'] ?? 'website');

        // Validation email
        if (empty($email)) {
            return $this->json(['error' => 'Email requis.'], 400);
        }

        $violations = $validator->validate($email, [new Email()]);
        if (count($violations) > 0) {
            return $this->json(['error' => 'Email invalide.'], 400);
        }

        // Vérifie si déjà inscrit
        $existing = $waitlistRepo->findOneBy(['email' => $email]);
        if ($existing) {
            return $this->json(['message' => 'Déjà inscrit.', 'already' => true]);
        }

        // Crée l'entrée
        $entry = new WaitlistEntry();
        $entry->setEmail($email);
        $entry->setFirstName($firstName ?: null);
        $entry->setSource($source);
        $entry->setNotified(false);
        $entry->setCreatedAt(new \DateTimeImmutable());

        $em->persist($entry);
        $em->flush();

        return $this->json([
            'success' => true,
            'message' => 'Inscription confirmée.',
        ]);
    }
}