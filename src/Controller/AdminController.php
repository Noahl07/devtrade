<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Document;
use App\Entity\DocumentCategory;
use App\Entity\Project;
use App\Entity\User;
use App\Repository\AdminLogRepository;
use App\Repository\DocumentCategoryRepository;
use App\Repository\DocumentRepository;
use App\Repository\PageViewRepository;
use App\Repository\ReadingProgressRepository;
use App\Repository\ProjectRepository;
use App\Repository\UserRepository;
use App\Repository\WaitlistEntryRepository;
use App\Service\Admin\AdminLogger;
use App\Service\Admin\ContentEditor;
use App\Service\Admin\DocumentationImport;
use App\Service\Admin\UserRemover;
use App\Service\Admin\WaitlistCsv;
use App\Service\Analytics\AnalyticsReport;
use App\Service\DocumentationBundle;
use App\Twig\SanitizeHtmlExtension;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Back-office. Routes déclarées dans config/routes.yaml (préfixe /admin, ROLE_ADMIN aussi exigé par access_control).
 * La validation et l'enregistrement sont dans App\Service\Admin\ContentEditor.
 */
#[IsGranted('ROLE_ADMIN')]
final class AdminController extends AbstractController
{
    private const PER_PAGE = 30;

    public function __construct(
        private readonly ContentEditor $editor,
        private readonly AdminLogger $journal,
    ) {
    }

    // ── Tableau de bord ─────────────────────────────────────────────────────

    public function index(UserRepository $users, DocumentRepository $documents, WaitlistEntryRepository $waitlist, ProjectRepository $projects): Response
    {
        return $this->render('admin/index.html.twig', [
            'stats' => [
                // Administrateurs exclus des compteurs (ils restent visibles dans le tableau des comptes).
                'users' => $users->countMembers(),
                'premium' => $users->countMembers(premiumOnly: true),
                'documents' => $documents->count(['isPublished' => true]),
                'drafts' => $documents->count(['isPublished' => false]),
                'waitlist' => $waitlist->count([]),
                'projects' => $projects->count(['isVisible' => true]),
            ],
            'latest_users' => $users->findBy([], ['createdAt' => 'DESC'], 5),
            'latest_waitlist' => $waitlist->findBy([], ['createdAt' => 'DESC'], 5),
        ]);
    }

    // ── Statistiques ────────────────────────────────────────────────────────

    public function statistics(Request $request, AnalyticsReport $report): Response
    {
        // Durée de conservation limitée : purge à chaque consultation (requête indexée, peu coûteuse).
        $report->purgeExpired();

        return $this->render('admin/statistics.html.twig', [
            'report' => $report->build($request->query->getInt('jours', 30)),
        ]);
    }

    // ── Articles ────────────────────────────────────────────────────────────

    public function documents(Request $request, DocumentRepository $documents, DocumentCategoryRepository $categories): Response
    {
        $filters = [
            'category' => ctype_digit((string) $request->query->get('category')) ? (int) $request->query->get('category') : null,
            'access' => in_array($request->query->get('access'), array_keys(ContentEditor::ACCESS_LEVELS), true) ? $request->query->get('access') : null,
            'status' => in_array($request->query->get('status'), ['published', 'draft'], true) ? $request->query->get('status') : null,
            'q' => mb_substr(trim((string) $request->query->get('q')), 0, 100),
        ];

        $qb = $documents->createQueryBuilder('d')->leftJoin('d.category', 'c')->addSelect('c')->orderBy('d.createdAt', 'DESC');
        if ($filters['category']) {
            $qb->andWhere('d.category = :cat')->setParameter('cat', $filters['category']);
        }
        if ($filters['access']) {
            $qb->andWhere('d.accessLevel = :access')->setParameter('access', $filters['access']);
        }
        if ($filters['status']) {
            $qb->andWhere('d.isPublished = :published')->setParameter('published', $filters['status'] === 'published');
        }
        if ($filters['q'] !== '') {
            $qb->andWhere('LOWER(d.title) LIKE :q OR LOWER(d.slug) LIKE :q')
                ->setParameter('q', '%' . addcslashes(mb_strtolower($filters['q']), '%_\\') . '%');
        }

        $total = (int) (clone $qb)->select('COUNT(d.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $request->query->getInt('page', 1)), $pages);

        return $this->render('admin/documents/index.html.twig', [
            'documents' => $qb->setFirstResult(($page - 1) * self::PER_PAGE)->setMaxResults(self::PER_PAGE)->getQuery()->getResult(),
            'categories' => $categories->findBy([], ['position' => 'ASC']),
            'filters' => $filters,
            'total' => $total,
            'pages' => $pages,
            'page' => $page,
        ]);
    }

    public function documentNew(Request $request, DocumentCategoryRepository $categories): Response
    {
        return $this->documentForm($request, new Document(), $categories);
    }

    public function documentEdit(Document $document, Request $request, DocumentCategoryRepository $categories): Response
    {
        return $this->documentForm($request, $document, $categories);
    }

    public function documentDelete(Document $document, Request $request, EntityManagerInterface $em): Response
    {
        $this->requireCsrf('delete-doc-' . $document->getId(), $request->request->get('_token'));
        $titre = (string) $document->getTitle();
        $em->remove($document);
        $em->flush();
        $this->journal->log('Article supprimé', $titre);
        $this->addFlash('success', sprintf('Article « %s » supprimé.', $titre));

        return $this->redirectToRoute('app_admin_documents');
    }

    /** Aperçu fidèle au site public : le contenu passe par le même filtre de nettoyage que l'article publié. */
    public function documentPreview(Request $request, SanitizeHtmlExtension $sanitizer): Response
    {
        $this->requireCsrf('admin_form', $request->headers->get('X-CSRF-Token'));

        return new Response($sanitizer->sanitize((string) $request->request->get('content', '')), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public function documentsReorder(Request $request): JsonResponse
    {
        return $this->reorder($request, Document::class);
    }

    // ── Modules ─────────────────────────────────────────────────────────────

    // ── Import du lot de documentation (produit en local par « app:documentation:files bundle ») ──

    public function documentationImport(Request $request, DocumentationImport $import, DocumentationBundle $bundles): Response
    {
        $plan = null;
        $token = null;
        if ($request->isMethod('POST')) {
            $this->requireCsrf('doc-import', $request->request->get('_token'));
            try {
                $stored = $import->store($request->files->get('bundle'));
                $token = $stored['token'];
                $plan = $bundles->plan($stored['bundle']);
                $request->getSession()->set('doc_import_token', $token);
            } catch (\InvalidArgumentException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        }

        return $this->render('admin/documents/import.html.twig', ['plan' => $plan, 'token' => $token]);
    }

    public function documentationImportConfirm(Request $request, DocumentationImport $import, DocumentationBundle $bundles): Response
    {
        $this->requireCsrf('doc-import-confirm', $request->request->get('_token'));
        $token = (string) $request->request->get('token');
        // Seul l'envoi fait dans cette session peut être confirmé.
        if ($token === '' || $request->getSession()->get('doc_import_token') !== $token) {
            $this->addFlash('danger', 'Envoi inconnu ou expiré. Renvoyez le fichier.');

            return $this->redirectToRoute('app_admin_documentation_import');
        }
        try {
            $user = $this->getUser();
            $plan = $bundles->apply($import->load($token), $user instanceof User ? $user : null);
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('danger', $e->getMessage());

            return $this->redirectToRoute('app_admin_documentation_import');
        }
        $import->forget($token);
        $request->getSession()->remove('doc_import_token');
        $this->journal->log('Import de la documentation', 'Paquet de documentation', sprintf('%d créé(s), %d mis à jour, %d module(s) créé(s), %d déplacé(s)',
            count($plan['articlesNew']), count($plan['articlesUpdated']), count($plan['modulesNew']), count($plan['modulesMoved'])));
        $this->addFlash('success', sprintf('Import terminé : %d article(s) créé(s), %d mis à jour, %d module(s) créé(s), %d déplacé(s). Rien n\'a été supprimé.',
            count($plan['articlesNew']), count($plan['articlesUpdated']), count($plan['modulesNew']), count($plan['modulesMoved'])));

        return $this->redirectToRoute('app_admin_documents');
    }

    public function categories(DocumentCategoryRepository $categories, DocumentRepository $documents): Response
    {
        $byCategory = [];
        foreach ($documents->findBy([], ['position' => 'ASC', 'id' => 'ASC']) as $document) {
            $byCategory[$document->getCategory()->getId()][] = $document;
        }

        return $this->render('admin/categories/index.html.twig', [
            'categories' => $categories->findBy([], ['position' => 'ASC']),
            'documentsByCategory' => $byCategory,
        ]);
    }

    public function categoryNew(Request $request): Response
    {
        return $this->categoryForm($request, new DocumentCategory());
    }

    public function categoryEdit(DocumentCategory $category, Request $request): Response
    {
        return $this->categoryForm($request, $category);
    }

    public function categoryDelete(DocumentCategory $category, Request $request, EntityManagerInterface $em, DocumentRepository $documents): Response
    {
        $this->requireCsrf('delete-cat-' . $category->getId(), $request->request->get('_token'));
        $count = $documents->count(['category' => $category]);
        if ($count > 0) {
            $this->addFlash('danger', sprintf('Impossible de supprimer « %s » : %d article(s) dedans. Déplacez-les d\'abord.', $category->getName(), $count));
        } else {
            $em->remove($category);
            $em->flush();
            $this->journal->log('Module supprimé', (string) $category->getName());
            $this->addFlash('success', sprintf('Module « %s » supprimé.', $category->getName()));
        }

        return $this->redirectToRoute('app_admin_categories');
    }

    public function categoriesReorder(Request $request): JsonResponse
    {
        return $this->reorder($request, DocumentCategory::class);
    }

    // ── Réalisations ────────────────────────────────────────────────────────

    public function projects(ProjectRepository $projects): Response
    {
        return $this->render('admin/projects/index.html.twig', [
            'projects' => $projects->findBy([], ['position' => 'ASC', 'id' => 'ASC']),
        ]);
    }

    public function projectNew(Request $request): Response
    {
        return $this->projectForm($request, (new Project())->setIsVisible(true)->setIsFeatured(false));
    }

    public function projectEdit(Project $project, Request $request): Response
    {
        return $this->projectForm($request, $project);
    }

    public function projectDelete(Project $project, Request $request, EntityManagerInterface $em): Response
    {
        $this->requireCsrf('delete-project-' . $project->getId(), $request->request->get('_token'));
        $em->remove($project);
        $em->flush();
        $this->journal->log('Réalisation supprimée', (string) $project->getTitle());
        $this->addFlash('success', sprintf('Réalisation « %s » supprimée.', $project->getTitle()));

        return $this->redirectToRoute('app_admin_projects');
    }

    // ── Liste d'attente ─────────────────────────────────────────────────────

    public function waitlist(WaitlistEntryRepository $waitlist): Response
    {
        return $this->render('admin/waitlist/index.html.twig', [
            'entries' => $waitlist->findBy([], ['createdAt' => 'DESC']),
        ]);
    }

    public function waitlistExport(WaitlistEntryRepository $waitlist, WaitlistCsv $csv): Response
    {
        $response = new Response($csv->build($waitlist->findBy([], ['createdAt' => 'ASC'])));
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            sprintf('liste-attente-%s.csv', date('Y-m-d')),
        ));
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    /** Droit à l'effacement (RGPD) : suppression d'une inscription à la demande de la personne. */
    public function waitlistDelete(int $id, Request $request, WaitlistEntryRepository $waitlist, EntityManagerInterface $em): Response
    {
        $entry = $waitlist->find($id) ?? throw $this->createNotFoundException();
        $this->requireCsrf('delete-waitlist-' . $id, $request->request->get('_token'));
        $em->remove($entry);
        $em->flush();
        $this->journal->log('Inscription liste d\'attente supprimée', '#' . $id);
        $this->addFlash('success', 'Inscription supprimée.');

        return $this->redirectToRoute('app_admin_waitlist');
    }

    // ── Utilisateurs ────────────────────────────────────────────────────────

    public function users(UserRepository $users, PageViewRepository $views): Response
    {
        $depuis = new \DateTimeImmutable('-5 minutes');

        return $this->render('admin/users/index.html.twig', [
            'users' => $users->findBy([], ['createdAt' => 'DESC']),
            'online_members' => $users->countOnlineMembers($depuis),
            'online_visitors' => $views->countVisitorsSince($depuis),
        ]);
    }

    public function togglePremium(int $id, Request $request, UserRepository $users, EntityManagerInterface $em): Response
    {
        $user = $users->find($id) ?? throw $this->createNotFoundException();
        $this->requireCsrf('toggle-premium-' . $id, $request->request->get('_token'));
        $user->setIsPremium(!$user->isPremium());
        $em->flush();
        $this->journal->log($user->isPremium() ? 'Accès Premium donné' : 'Accès Premium retiré', (string) $user->getEmail());
        $this->addFlash('success', sprintf('%s : accès %s.', $user->getFullName(), $user->isPremium() ? 'Premium' : 'gratuit'));

        return $this->redirectToRoute('app_admin_users');
    }

    /** Suppression d'un compte (droit à l'effacement, comptes de test). */
    public function userDelete(int $id, Request $request, UserRepository $users, UserRemover $remover): Response
    {
        $user = $users->find($id) ?? throw $this->createNotFoundException();
        $this->requireCsrf('delete-user-' . $id, $request->request->get('_token'));
        $current = $this->getUser();
        $refusal = $remover->refusal($user, $current instanceof User ? $current : null);
        if ($refusal !== null) {
            $this->addFlash('danger', $refusal);

            return $this->redirectToRoute('app_admin_users');
        }
        $name = $user->getFullName();
        $email = (string) $user->getEmail();
        $remover->remove($user);
        $this->journal->log('Compte supprimé', $email, $name);
        $this->addFlash('success', sprintf('Compte de %s supprimé.', $name));

        return $this->redirectToRoute('app_admin_users');
    }

    // ── Journal et lecture ──────────────────────────────────────────────────

    public function journal(AdminLogRepository $logs): Response
    {
        return $this->render('admin/journal.html.twig', ['logs' => $logs->findLatest()]);
    }

    public function reading(ReadingProgressRepository $progress): Response
    {
        return $this->render('admin/reading.html.twig', ['rows' => $progress->statsByDocument(50)]);
    }

    // ── Formulaires communs ─────────────────────────────────────────────────

    private function documentForm(Request $request, Document $document, DocumentCategoryRepository $categories): Response
    {
        $errors = [];
        $values = null;
        if ($request->isMethod('POST')) {
            $this->requireCsrf('admin_form', $request->request->get('_token'));
            $values = $request->request->all();
            $errors = $this->editor->saveDocument($document, $values);
            if ($errors === []) {
                $this->journal->log('Article enregistré', (string) $document->getTitle());
                $this->addFlash('success', sprintf('Article « %s » enregistré.', $document->getTitle()));

                return $this->redirectToRoute($request->request->has('stay') ? 'app_admin_document_edit' : 'app_admin_documents', $request->request->has('stay') ? ['id' => $document->getId()] : []);
            }
        }

        return $this->render('admin/documents/form.html.twig', [
            'document' => $document,
            'values' => $values,
            'errors' => $errors,
            'categories' => $categories->findBy([], ['position' => 'ASC']),
            'access_levels' => ContentEditor::ACCESS_LEVELS,
        ], new Response(status: $errors === [] ? 200 : 422));
    }

    private function categoryForm(Request $request, DocumentCategory $category): Response
    {
        $errors = [];
        $values = null;
        if ($request->isMethod('POST')) {
            $this->requireCsrf('admin_form', $request->request->get('_token'));
            $values = $request->request->all();
            $errors = $this->editor->saveCategory($category, $values);
            if ($errors === []) {
                $this->journal->log('Module enregistré', (string) $category->getName());
                $this->addFlash('success', sprintf('Module « %s » enregistré.', $category->getName()));

                return $this->redirectToRoute('app_admin_categories');
            }
        }

        return $this->render('admin/categories/form.html.twig', [
            'category' => $category,
            'values' => $values,
            'errors' => $errors,
            'access_levels' => ContentEditor::ACCESS_LEVELS,
        ], new Response(status: $errors === [] ? 200 : 422));
    }

    private function projectForm(Request $request, Project $project): Response
    {
        $errors = [];
        $values = null;
        if ($request->isMethod('POST')) {
            $this->requireCsrf('admin_form', $request->request->get('_token'));
            $values = $request->request->all();
            $errors = $this->editor->saveProject($project, $values);
            if ($errors === []) {
                $this->journal->log('Réalisation enregistrée', (string) $project->getTitle());
                $this->addFlash('success', sprintf('Réalisation « %s » enregistrée.', $project->getTitle()));

                return $this->redirectToRoute('app_admin_projects');
            }
        }

        return $this->render('admin/projects/form.html.twig', [
            'project' => $project,
            'values' => $values,
            'errors' => $errors,
            'types' => ContentEditor::PROJECT_TYPES,
        ], new Response(status: $errors === [] ? 200 : 422));
    }

    /** @param class-string<Document|DocumentCategory> $class */
    private function reorder(Request $request, string $class): JsonResponse
    {
        $this->requireCsrf('admin_form', $request->headers->get('X-CSRF-Token'));
        $ids = json_decode($request->getContent(), true)['ids'] ?? null;
        if (!is_array($ids) || $ids === [] || count($ids) > 500) {
            return $this->json(['success' => false, 'error' => 'Liste invalide.'], 400);
        }
        try {
            $count = $this->editor->reorder($class, $ids);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 400);
        }

        return $this->json(['success' => true, 'count' => $count]);
    }

    private function requireCsrf(string $id, mixed $token): void
    {
        if (!$this->isCsrfTokenValid($id, is_string($token) ? $token : '')) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide. Rechargez la page.');
        }
    }
}
