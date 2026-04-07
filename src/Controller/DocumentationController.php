<?php

namespace App\Controller;

use App\Repository\DocumentCategoryRepository;
use App\Repository\DocumentRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class DocumentationController extends AbstractController
{
    #[Route('/documentation', name: 'app_documentation', methods: ['GET'])]
    public function index(
        DocumentCategoryRepository $categoryRepo,
        DocumentRepository $documentRepo
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

        return $this->render('documentation/index.html.twig', [
            'categories'    => $categories,
            'articleCounts' => $articleCounts,
            'totalArticles' => $totalArticles,
            'totalMinutes'  => $totalMinutes,
            'moduleCount'   => count($categories),
        ]);
    }

    #[Route('/documentation/categorie/{slug}', name: 'app_documentation_category', methods: ['GET'])]
    public function category(
        string $slug,
        DocumentCategoryRepository $categoryRepo,
        DocumentRepository $documentRepo
    ): Response {
        $category = $categoryRepo->findOneBy(['slug' => $slug]);

        if (!$category) {
            throw $this->createNotFoundException('Catégorie introuvable.');
        }

        // Vérifie l'accès premium
        if ($category->getAccessLevel() === 'premium') {
            $this->denyAccessUnlessGranted('ROLE_PREMIUM', null,
                'Cette catégorie est réservée aux membres Premium.'
            );
        }

        return $this->render('documentation/category.html.twig', [
            'category'  => $category,
            'documents' => $documentRepo->findByCategory($category),
        ]);
    }

    #[Route('/documentation/article/{accessLevel}/{slug}', name: 'app_documentation_article', methods: ['GET'],
        requirements: ['accessLevel' => 'free|premium']
    )]
    public function article(
        string $accessLevel,
        string $slug,
        DocumentRepository $documentRepo
    ): Response {
        // Bloque l'accès premium si non autorisé
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

        // Double vérification accès au niveau de l'entité
        if ($document->getAccessLevel() === 'premium' && !$this->isGranted('ROLE_PREMIUM')) {
            $this->addFlash('warning', 'Cet article est réservé aux membres Premium.');
            return $this->redirectToRoute('app_documentation');
        }

        return $this->render('documentation/article.html.twig', [
            'document' => $document,
        ]);
    }
}