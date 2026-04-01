<?php

namespace App\Controller;

use App\Repository\ProjectRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class PortfolioController extends AbstractController
{
    #[Route('/portfolio', name: 'app_portfolio', methods: ['GET'])]
    public function index(ProjectRepository $projectRepo): Response
    {
        $projects = $projectRepo->findBy(
            ['isVisible' => true],
            ['position' => 'ASC']
        );

        return $this->render('portfolio/index.html.twig', [
            'projects' => $projects,
        ]);
    }

    #[Route('/portfolio/{slug}', name: 'app_portfolio_project', methods: ['GET'])]
    public function show(string $slug, ProjectRepository $projectRepo): Response
    {
        $project = $projectRepo->findOneBy([
            'slug'      => $slug,
            'isVisible' => true,
        ]);

        if (!$project) {
            throw $this->createNotFoundException('Projet introuvable.');
        }

        return $this->render('portfolio/show.html.twig', [
            'project' => $project,
        ]);
    }
}