<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;

/**
 * Styleguide du design system. Route déclarée dans config/routes/styleguide.yaml
 * sous when@dev : elle n'existe pas en prod (404).
 */
final class StyleguideController extends AbstractController
{
    public function index(): Response
    {
        return $this->render('styleguide/index.html.twig');
    }
}
