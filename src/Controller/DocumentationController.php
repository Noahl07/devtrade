<?php

namespace App\Controller;

use App\Repository\DocumentCategoryRepository;
use App\Repository\DocumentRepository;
use App\Service\TranslationCacheService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class DocumentationController extends AbstractController
{
    public function __construct(
        private TranslationCacheService $translator
    ) {}

    #[Route('/documentation', name: 'app_documentation', methods: ['GET'])]
    public function index(
        Request $request,
        DocumentCategoryRepository $categoryRepo,
        DocumentRepository $documentRepo
    ): Response {
        $locale        = $request->getLocale();
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

        // Traduire noms + descriptions des catégories si EN
        if ($locale !== 'fr') {
            $names        = array_map(fn($c) => $c->getName(), $categories);
            $descriptions = array_map(fn($c) => $c->getDescription() ?? '', $categories);

            $translatedNames = $this->translator->translateBatch($names, $locale);
            $translatedDescs = $this->translator->translateBatch($descriptions, $locale);

            foreach ($categories as $i => $cat) {
                $cat->setName($translatedNames[$i]);
                $cat->setDescription($translatedDescs[$i] ?: null);
            }
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
        Request $request,
        DocumentCategoryRepository $categoryRepo,
        DocumentRepository $documentRepo
    ): Response {
        $locale   = $request->getLocale();
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

        // Traduire si EN
        if ($locale !== 'fr') {
            // Catégorie
            $category->setName(
                $this->translator->translate($category->getName(), $locale)
            );
            if ($category->getDescription()) {
                $category->setDescription(
                    $this->translator->translate($category->getDescription(), $locale)
                );
            }

            // Titres + extraits des articles de la liste
            $titles   = array_map(fn($d) => $d->getTitle(), $documents);
            $excerpts = array_map(fn($d) => $d->getExcerpt() ?? '', $documents);

            $translatedTitles   = $this->translator->translateBatch($titles, $locale);
            $translatedExcerpts = $this->translator->translateBatch($excerpts, $locale);

            foreach ($documents as $i => $doc) {
                $doc->setTitle($translatedTitles[$i]);
                $doc->setExcerpt($translatedExcerpts[$i] ?: null);
            }
        }

        return $this->render('documentation/category.html.twig', [
            'category'  => $category,
            'documents' => $documents,
        ]);
    }

    #[Route('/documentation/article/{accessLevel}/{slug}', name: 'app_documentation_article', methods: ['GET'],
        requirements: ['accessLevel' => 'free|premium']
    )]
    public function article(
        string $accessLevel,
        string $slug,
        Request $request,
        DocumentRepository $documentRepo
    ): Response {
        $locale = $request->getLocale();

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

        // Traduire le contenu complet de l'article si EN
        if ($locale !== 'fr') {
            $document->setTitle(
                $this->translator->translate($document->getTitle(), $locale)
            );
            if ($document->getExcerpt()) {
                $document->setExcerpt(
                    $this->translator->translate($document->getExcerpt(), $locale)
                );
            }
            // Le contenu HTML de l'article (le plus gros bloc)
            // textType=html dans l'API → les balises sont préservées
            $document->setContent(
                $this->translator->translate($document->getContent(), $locale)
            );
        }

        return $this->render('documentation/article.html.twig', [
            'document' => $document,
        ]);
    }
}