<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\DocumentCategoryRepository;
use App\Repository\DocumentRepository;
use App\Repository\ProjectRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(
        ProjectRepository $projectRepo,
        DocumentCategoryRepository $categoryRepo,
        DocumentRepository $documentRepo,
        #[Autowire('%shiro.demo_since%')] string $demoSince,
    ): Response {
        // Réalisations réelles uniquement (base de données) : aucun projet inventé.
        $featuredProjects = $projectRepo->findBy(
            ['isFeatured' => true, 'isVisible' => true],
            ['position' => 'ASC'],
            3
        );

        $categories = [];
        foreach ($categoryRepo->findBy([], ['position' => 'ASC'], 6) as $category) {
            $categories[] = [
                'entity' => $category,
                'count'  => $documentRepo->countByCategory($category),
            ];
        }

        return $this->render('home/index.html.twig', [
            'featuredProjects' => $featuredProjects,
            'categories'       => $categories,
            'demo_since'       => $demoSince,
        ]);
    }
}
