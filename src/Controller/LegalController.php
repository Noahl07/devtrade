<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Pages légales. Routes déclarées dans config/routes.yaml ; identité de l'éditeur dans config/packages/legal.yaml.
 */
class LegalController extends AbstractController
{
    /** @param array<string, mixed> $legal */
    public function __construct(
        #[Autowire([
            'validated'            => '%legal.validated%',
            'updated_at'           => '%legal.updated_at%',
            'editor_name'          => '%legal.editor_name%',
            'editor_status'        => '%legal.editor_status%',
            'editor_siret'         => '%legal.editor_siret%',
            'editor_vat'           => '%legal.editor_vat%',
            'editor_address'       => '%legal.editor_address%',
            'publication_director' => '%legal.publication_director%',
            'host_name'            => '%legal.host_name%',
            'host_address'         => '%legal.host_address%',
            'email_provider'       => '%legal.email_provider%',
            'retention_account'    => '%legal.retention_account%',
            'retention_contact'    => '%legal.retention_contact%',
        ])]
        private readonly array $legal,
    ) {
    }

    #[Route('/conditions-utilisation', name: 'app_cgu', methods: ['GET'])]
    public function cgu(): Response
    {
        return $this->render('legal/cgu.html.twig', ['legal' => $this->legal]);
    }

    #[Route('/mentions-legales', name: 'app_legal_notice', methods: ['GET'])]
    public function notice(): Response
    {
        return $this->render('legal/mentions.html.twig', ['legal' => $this->legal]);
    }

    #[Route('/confidentialite', name: 'app_privacy', methods: ['GET'])]
    public function privacy(): Response
    {
        return $this->render('legal/confidentialite.html.twig', ['legal' => $this->legal]);
    }

    #[Route('/risques', name: 'app_risks', methods: ['GET'])]
    public function risks(): Response
    {
        return $this->render('legal/risques.html.twig', ['legal' => $this->legal]);
    }
}
