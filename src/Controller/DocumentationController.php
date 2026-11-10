<?php

namespace App\Controller;

use App\Entity\Document;
use App\Entity\User;
use App\Repository\DocumentCategoryRepository;
use App\Repository\DocumentRepository;
use App\Repository\ReadingProgressRepository;
use App\Service\ReadingProgressTracker;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class DocumentationController extends AbstractController
{
    #[Route('/documentation', name: 'app_documentation', methods: ['GET'])]
    public function index(
        Request $request,
        DocumentCategoryRepository $categoryRepo,
        DocumentRepository $documentRepo,
        ReadingProgressRepository $progressRepo,
        ReadingProgressTracker $tracker
    ): Response {
        $categories    = $categoryRepo->findBy([], ['position' => 'ASC']);
        $totalArticles = 0;
        $totalMinutes  = 0;
        $articleCounts = [];

        foreach ($categories as $cat) {
            $count = $documentRepo->countByCategory($cat);
            $articleCounts[$cat->getSlug()] = $count;
            $totalArticles += $count;
        }

        foreach ($documentRepo->findBy(['isPublished' => true]) as $doc) {
            $totalMinutes += $doc->getReadingTime() ?? 0;
        }

        $user = $this->getUser();
        $progress = [];
        $resume = null;
        if ($user instanceof User) {
            $progress = $tracker->byCategory($categories, $progressRepo->mapFor($user));
            $resume = $progressRepo->findResumable($user)?->getDocument();
            if ($resume !== null && !$this->canRead($resume)) {
                $resume = null;
            }
        }

        return $this->render('documentation/index.html.twig', [
            'categories'    => $categories,
            'articleCounts' => $articleCounts,
            'totalArticles' => $totalArticles,
            'totalMinutes'  => $totalMinutes,
            'moduleCount'   => count($categories),
            'progress'      => $progress,
            'resume'        => $resume,
        ]);
    }

    #[Route('/documentation/recherche', name: 'app_documentation_search', methods: ['GET'])]
    public function search(Request $request, DocumentRepository $documentRepo, DocumentCategoryRepository $categoryRepo): Response
    {
        $terms = trim(mb_substr((string) $request->query->get('q', ''), 0, 80));
        $results = mb_strlen($terms) >= 2 ? $documentRepo->search($terms, $this->isGranted('ROLE_PREMIUM')) : [];

        return $this->render('documentation/search.html.twig', [
            'terms'   => $terms,
            'results' => $results,
            'modules' => $categoryRepo->findBy([], ['position' => 'ASC']),
        ]);
    }

    #[Route('/documentation/categorie/{slug}', name: 'app_documentation_category', methods: ['GET'])]
    public function category(
        string $slug,
        Request $request,
        DocumentCategoryRepository $categoryRepo,
        DocumentRepository $documentRepo,
        ReadingProgressRepository $progressRepo
    ): Response {
        $category = $categoryRepo->findOneBy(['slug' => $slug]);

        if (!$category) {
            throw $this->createNotFoundException('Catégorie introuvable.');
        }

        if ($category->getAccessLevel() === 'premium') {
            $this->denyAccessUnlessGranted('ROLE_PREMIUM', null,
                'Cette catégorie est réservée aux membres Premium.'
            );
        }

        $documents = $documentRepo->findByCategory($category);
        $user = $this->getUser();

        return $this->render('documentation/category.html.twig', [
            'category'  => $category,
            'documents' => $documents,
            'modules'   => $categoryRepo->findBy([], ['position' => 'ASC']),
            'progress'  => $user instanceof User ? $progressRepo->mapFor($user) : [],
        ]);
    }

    #[Route('/documentation/article/{accessLevel}/{slug}', name: 'app_documentation_article', methods: ['GET'],
        requirements: ['accessLevel' => 'free|premium']
    )]
    public function article(
        string $accessLevel,
        string $slug,
        Request $request,
        DocumentRepository $documentRepo,
        DocumentCategoryRepository $categoryRepo,
        ReadingProgressRepository $progressRepo,
        ReadingProgressTracker $tracker
    ): Response {

        if ($accessLevel === 'premium') {
            if (!$this->getUser()) {
                $this->addFlash('info', 'Connectez-vous pour accéder aux articles premium.');
                return $this->redirectToRoute('app_login');
            }
            $this->denyAccessUnlessGranted('ROLE_PREMIUM', null,
                'Cet article est réservé aux membres Premium.'
            );
        }

        $document = $documentRepo->findOneBy([
            'slug'        => $slug,
            'isPublished' => true,
        ]);

        if (!$document) {
            throw $this->createNotFoundException('Article introuvable.');
        }

        if ($document->getAccessLevel() === 'premium' && !$this->isGranted('ROLE_PREMIUM')) {
            $this->addFlash('warning', 'Cet article est réservé aux membres Premium.');
            return $this->redirectToRoute('app_documentation');
        }

        $user = $this->getUser();
        $map = [];
        $current = null;
        if ($user instanceof User) {
            $current = $tracker->opened($user, $document);
            $map = $progressRepo->mapFor($user);
        }

        return $this->render('documentation/article.html.twig', [
            'document' => $document,
            'modules'  => $categoryRepo->findBy([], ['position' => 'ASC']),
            'progress' => $map,
            'current'  => $current,
        ]);
    }

    /**
     * Progression envoyée par l'article : « lu », « non-lu », ou score du quiz.
     * Répond en JSON aux appels du script, redirige vers l'article pour un envoi de formulaire classique.
     */
    #[Route('/documentation/progression/{id}', name: 'app_documentation_progress', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function progress(Document $document, Request $request, ReadingProgressTracker $tracker): Response
    {
        if (!$this->isCsrfTokenValid('progress', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }
        if (!$document->isPublished() || !$this->canRead($document)) {
            throw $this->createAccessDeniedException();
        }
        $action = (string) $request->request->get('action');
        if (!in_array($action, ReadingProgressTracker::ACTIONS, true)) {
            throw $this->createNotFoundException();
        }

        /** @var User $user */
        $user = $this->getUser();
        $p = $tracker->apply($user, $document, $action, $request->request->getInt('score'), $request->request->getInt('total'));

        if ($request->headers->get('Accept') === 'application/json') {
            return new JsonResponse(['completed' => $p->isCompleted(), 'quizScore' => $p->getQuizScore(), 'quizTotal' => $p->getQuizTotal()]);
        }

        return $this->redirectToRoute('app_documentation_article', ['accessLevel' => $document->getAccessLevel(), 'slug' => $document->getSlug()]);
    }

    private function canRead(Document $document): bool
    {
        return !$document->isPremium() || $this->isGranted('ROLE_PREMIUM');
    }
}
