<?php

namespace App\Controller;

use App\Repository\DocumentCategoryRepository;
use App\Repository\DocumentRepository;
use App\Repository\ProjectRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class SitemapController extends AbstractController
{
    #[Route('/sitemap.xml', name: 'app_sitemap', methods: ['GET'])]
    public function sitemap(
        DocumentRepository $docRepo,
        DocumentCategoryRepository $catRepo,
        ProjectRepository $projectRepo
    ): Response {
        $urls = [];

        // Pages statiques
        $staticRoutes = [
            ['route' => 'app_home',          'priority' => '1.0', 'freq' => 'weekly'],
            ['route' => 'app_portfolio',      'priority' => '0.9', 'freq' => 'weekly'],
            ['route' => 'app_trading',        'priority' => '0.9', 'freq' => 'monthly'],
            ['route' => 'app_documentation',  'priority' => '0.9', 'freq' => 'weekly'],
            ['route' => 'app_contact',        'priority' => '0.7', 'freq' => 'monthly'],
            ['route' => 'app_register',       'priority' => '0.6', 'freq' => 'monthly'],
        ];

        foreach ($staticRoutes as $r) {
            $urls[] = [
                'loc'        => $this->generateUrl($r['route'], [], UrlGeneratorInterface::ABSOLUTE_URL),
                'priority'   => $r['priority'],
                'changefreq' => $r['freq'],
                'lastmod'    => date('Y-m-d'),
            ];
        }

        // Projets
        foreach ($projectRepo->findBy(['isVisible' => true]) as $project) {
            $urls[] = [
                'loc'        => $this->generateUrl('app_portfolio_project', ['slug' => $project->getSlug()], UrlGeneratorInterface::ABSOLUTE_URL),
                'priority'   => '0.8',
                'changefreq' => 'monthly',
                'lastmod'    => $project->getCreatedAt()->format('Y-m-d'),
            ];
        }

        // Catégories documentation
        foreach ($catRepo->findBy([], ['position' => 'ASC']) as $cat) {
            $urls[] = [
                'loc'        => $this->generateUrl('app_documentation_category', ['slug' => $cat->getSlug()], UrlGeneratorInterface::ABSOLUTE_URL),
                'priority'   => '0.8',
                'changefreq' => 'weekly',
                'lastmod'    => date('Y-m-d'),
            ];
        }

        // Articles gratuits uniquement (les premium ne sont pas indexés)
        foreach ($docRepo->findPublishedByAccess('free') as $doc) {
            $urls[] = [
                'loc'        => $this->generateUrl('app_documentation_article', [
                    'accessLevel' => 'free',
                    'slug'        => $doc->getSlug(),
                ], UrlGeneratorInterface::ABSOLUTE_URL),
                'priority'   => '0.7',
                'changefreq' => 'monthly',
                'lastmod'    => ($doc->getUpdatedAt() ?? $doc->getCreatedAt())->format('Y-m-d'),
            ];
        }

        $response = new Response(
            $this->renderView('sitemap.xml.twig', ['urls' => $urls]),
            Response::HTTP_OK,
            ['Content-Type' => 'application/xml']
        );

        return $response;
    }

    #[Route('/robots.txt', name: 'app_robots', methods: ['GET'])]
    public function robots(): Response
    {
        return new Response(
            $this->renderView('robots.txt.twig'),
            Response::HTTP_OK,
            ['Content-Type' => 'text/plain']
        );
    }
}