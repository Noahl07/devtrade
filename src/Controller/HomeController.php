<?php

namespace App\Controller;

use App\Repository\ProjectRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(ProjectRepository $projectRepo): Response
    {
        $featuredProjects = $projectRepo->findBy(
            ['isFeatured' => true, 'isVisible' => true],
            ['position' => 'ASC'],
            3
        );

        return $this->render('home/index.html.twig', [
            'featuredProjects' => $featuredProjects,
        ]);
    }
}