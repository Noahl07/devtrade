<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Repository\TradePostRepository;
use App\Service\Community\TradeWall;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Modération de la « Salle des trades » : file d'attente, signalements, validation, refus, suppression.
 */
#[IsGranted('ROLE_ADMIN')]
final class CommunityModerationController extends AbstractController
{
    public function __construct(
        private readonly TradePostRepository $posts,
        private readonly TradeWall $wall,
    ) {
    }

    public function index(): Response
    {
        return $this->render('admin/community/index.html.twig', [
            'pending' => $this->posts->findPending(),
            'reported' => $this->posts->findReported(),
        ]);
    }

    public function approve(int $id, Request $request): Response
    {
        $post = $this->posts->find($id) ?? throw $this->createNotFoundException();
        $this->checkCsrf('moderation-' . $id, $request);
        $this->wall->approve($post);
        $this->addFlash('success', 'Publication validée.');

        return $this->redirectToRoute('app_admin_community');
    }

    public function reject(int $id, Request $request): Response
    {
        $post = $this->posts->find($id) ?? throw $this->createNotFoundException();
        $this->checkCsrf('moderation-' . $id, $request);
        $this->wall->reject($post, $request->request->getString('raison'));
        $this->addFlash('success', 'Publication refusée : l\'auteur voit le motif dans « Mes publications ».');

        return $this->redirectToRoute('app_admin_community');
    }

    public function delete(int $id, Request $request): Response
    {
        $post = $this->posts->find($id) ?? throw $this->createNotFoundException();
        $this->checkCsrf('moderation-' . $id, $request);
        $this->wall->delete($post, true);
        $this->addFlash('success', 'Publication supprimée.');

        return $this->redirectToRoute('app_admin_community');
    }

    private function checkCsrf(string $id, Request $request): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }
    }
}
