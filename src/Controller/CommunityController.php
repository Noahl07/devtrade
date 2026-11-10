<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\TradeImage;
use App\Entity\TradePost;
use App\Entity\TradeReaction;
use App\Entity\TradeReport;
use App\Entity\User;
use App\Form\TradePostType;
use App\Repository\TradePostRepository;
use App\Service\Community\TradeWall;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * « Salle des trades » : captures de trades partagées entre membres connectés, avec réactions.
 * Contrôleur fin : la logique est dans App\Service\Community\TradeWall.
 */
#[IsGranted('ROLE_USER')]
final class CommunityController extends AbstractController
{
    private const PAR_PAGE = 12;

    public function __construct(
        private readonly TradePostRepository $posts,
        private readonly TradeWall $wall,
    ) {
    }

    public function index(Request $request): Response
    {
        $theme = $request->query->getString('theme');
        $theme = isset(TradePost::THEMES[$theme]) ? $theme : null;
        $tri = $request->query->getString('tri') === 'populaires' ? 'populaires' : 'recents';
        $page = max(1, $request->query->getInt('page', 1));
        $resultat = $this->posts->findPublished($theme, $tri, $page, self::PAR_PAGE);

        return $this->render('community/index.html.twig', [
            'posts' => $resultat['items'],
            'total' => $resultat['total'],
            'pages' => max(1, (int) ceil($resultat['total'] / self::PAR_PAGE)),
            'page' => $page,
            'theme' => $theme,
            'tri' => $tri,
        ] + $this->reactionsFor($resultat['items']));
    }

    public function new(
        Request $request,
        #[Autowire(service: 'limiter.trade_post')] RateLimiterFactory $limiter,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $post = new TradePost($user);
        $form = $this->createForm(TradePostType::class, $post);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (!$limiter->create('membre-' . $user->getId())->consume()->isAccepted()) {
                $this->addFlash('danger', 'Vous avez atteint la limite de publications pour aujourd\'hui. Réessayez demain.');

                return $this->redirectToRoute('app_community');
            }
            /** @var list<UploadedFile> $files */
            $files = array_values(array_filter((array) $form->get('images')->getData(), static fn ($f): bool => $f instanceof UploadedFile));
            try {
                $this->wall->publish($post, $files);
                $this->addFlash('success', $this->wall->premoderation()
                    ? 'Publication envoyée. Elle apparaîtra après validation, en général sous 24 heures.'
                    : 'Publication en ligne.');

                return $this->redirectToRoute('app_community_mine');
            } catch (\InvalidArgumentException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        }

        return $this->render('community/new.html.twig', [
            'form' => $form,
            'premoderation' => $this->wall->premoderation(),
        ], new Response(status: $form->isSubmitted() && !$form->isValid() ? 422 : 200));
    }

    public function mine(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $posts = $this->posts->findByAuthor($user);

        return $this->render('community/mine.html.twig', ['posts' => $posts] + $this->reactionsFor($posts));
    }

    public function rules(): Response
    {
        return $this->render('community/rules.html.twig');
    }

    public function show(int $id): Response
    {
        $post = $this->visible($id);

        return $this->render('community/show.html.twig', [
            'post' => $post,
            'motifs' => TradeReport::MOTIFS,
        ] + $this->reactionsFor([$post]));
    }

    /** Capture : servie seulement aux membres qui ont le droit de voir la publication. */
    public function image(int $id, EntityManagerInterface $em): Response
    {
        $image = $em->find(TradeImage::class, $id) ?? throw $this->createNotFoundException();
        $this->visible((int) $image->getPost()->getId());

        return new Response($image->getData(), 200, [
            'Content-Type' => $image->getMimeType(),
            'Content-Disposition' => 'inline; filename="capture-' . $image->getId() . '.' . ($image->getMimeType() === 'image/webp' ? 'webp' : 'jpg') . '"',
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function react(int $id, Request $request): Response
    {
        $post = $this->visible($id);
        $this->checkCsrf('reaction-' . $id, $request);
        if (!$post->isPublished()) {
            throw $this->createAccessDeniedException();
        }
        /** @var User $user */
        $user = $this->getUser();
        $emoji = $request->request->getString('emoji');
        try {
            $actif = $this->wall->toggleReaction($post, $user, $emoji);
        } catch (\InvalidArgumentException) {
            throw $this->createNotFoundException();
        }
        if ($request->headers->get('Accept') === 'application/json') {
            $compte = $this->posts->reactionCounts([$post])[$id][$emoji] ?? 0;

            return new JsonResponse(['actif' => $actif, 'compte' => $compte]);
        }

        return $this->redirectToRoute('app_community_show', ['id' => $id]);
    }

    public function report(int $id, Request $request): Response
    {
        $post = $this->visible($id);
        $this->checkCsrf('signalement-' . $id, $request);
        /** @var User $user */
        $user = $this->getUser();
        if ($post->getAuthor() === $user) {
            throw $this->createAccessDeniedException();
        }
        $nouveau = $this->wall->report($post, $user, $request->request->getString('motif'));
        $this->addFlash('success', $nouveau ? 'Merci, la publication est signalée à l\'équipe.' : 'Vous avez déjà signalé cette publication.');

        return $this->redirectToRoute('app_community');
    }

    public function delete(int $id, Request $request): Response
    {
        $post = $this->posts->find($id) ?? throw $this->createNotFoundException();
        if ($post->getAuthor() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }
        $this->checkCsrf('suppression-' . $id, $request);
        $this->wall->delete($post, false);
        $this->addFlash('success', 'Publication supprimée.');

        return $this->redirectToRoute('app_community_mine');
    }

    /** Publication visible : validée, ou à soi, ou pour un administrateur. */
    private function visible(int $id): TradePost
    {
        $post = $this->posts->find($id) ?? throw $this->createNotFoundException();
        if (!$post->isPublished() && $post->getAuthor() !== $this->getUser() && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createNotFoundException();
        }

        return $post;
    }

    private function checkCsrf(string $id, Request $request): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }
    }

    /**
     * @param list<TradePost> $posts
     *
     * @return array{counts: array<int, array<string, int>>, mine: array<int, array<string, true>>, emojis: array<string, array{0: string, 1: string}>}
     */
    private function reactionsFor(array $posts): array
    {
        /** @var User $user */
        $user = $this->getUser();

        return [
            'counts' => $this->posts->reactionCounts($posts),
            'mine' => $this->posts->reactionsOf($user, $posts),
            'emojis' => TradeReaction::EMOJIS,
        ];
    }
}
