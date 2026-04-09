<?php

namespace App\Controller;

use App\Entity\Document;
use App\Entity\DocumentCategory;
use App\Entity\Project;
use App\Repository\DocumentCategoryRepository;
use App\Repository\DocumentRepository;
use App\Repository\ProjectRepository;
use App\Repository\UserRepository;
use App\Repository\WaitlistEntryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin')]
class AdminController extends AbstractController
{
    // ─────────────────────────────────────────
    // DASHBOARD
    // ─────────────────────────────────────────

    #[Route('', name: 'app_admin', methods: ['GET'])]
    public function index(
        UserRepository $userRepo,
        DocumentRepository $docRepo,
        WaitlistEntryRepository $waitlistRepo,
        ProjectRepository $projectRepo
    ): Response {
        return $this->render('admin/index.html.twig', [
            'stats' => [
                'users'     => $userRepo->count([]),
                'premium'   => $userRepo->count(['isPremium' => true]),
                'documents' => $docRepo->count(['isPublished' => true]),
                'drafts'    => $docRepo->count(['isPublished' => false]),
                'waitlist'  => $waitlistRepo->count([]),
                'projects'  => $projectRepo->count(['isVisible' => true]),
            ],
            'latest_users'    => $userRepo->findBy([], ['createdAt' => 'DESC'], 5),
            'latest_waitlist' => $waitlistRepo->findBy([], ['createdAt' => 'DESC'], 5),
        ]);
    }

    // ─────────────────────────────────────────
    // DOCUMENTS — liste
    // ─────────────────────────────────────────

    #[Route('/documents', name: 'app_admin_documents', methods: ['GET'])]
    public function documents(
        DocumentRepository $docRepo,
        DocumentCategoryRepository $catRepo
    ): Response {
        return $this->render('admin/documents/index.html.twig', [
            'documents'  => $docRepo->findBy([], ['createdAt' => 'DESC']),
            'categories' => $catRepo->findBy([], ['position' => 'ASC']),
        ]);
    }

    // ─────────────────────────────────────────
    // DOCUMENTS — nouveau
    // ─────────────────────────────────────────

    #[Route('/documents/nouveau', name: 'app_admin_document_new', methods: ['GET', 'POST'])]
    public function documentNew(
        Request $request,
        EntityManagerInterface $em,
        DocumentCategoryRepository $catRepo,
        DocumentRepository $docRepo,
        SluggerInterface $slugger
    ): Response {
        $categories = $catRepo->findBy([], ['position' => 'ASC']);
        $error = null;

        if ($request->isMethod('POST')) {
            $data = $request->request->all();

            if (empty($data['title']) || empty($data['category'])) {
                $error = 'Le titre et la catégorie sont obligatoires.';
            } else {
                $category = $catRepo->find((int)$data['category']);
                if (!$category) {
                    $error = 'Catégorie introuvable.';
                } else {
                    // Auto-position si non renseigné
                    $position = !empty($data['position'])
                        ? (int)$data['position']
                        : $docRepo->getMaxPositionInCategory($category) + 1;

                    $doc = new Document();
                    $doc->setTitle($data['title']);
                    $doc->setSlug(strtolower($slugger->slug($data['title'])));
                    $doc->setContent($data['content'] ?? '');
                    $doc->setExcerpt($data['excerpt'] ?? null);
                    $doc->setAccessLevel($data['accessLevel'] ?? 'free');
                    $doc->setReadingTime(!empty($data['readingTime']) ? (int)$data['readingTime'] : null);
                    $doc->setPosition($position);
                    $doc->setIsPublished(isset($data['isPublished']));
                    $doc->setCategory($category);
                    $doc->setCreatedAt(new \DateTimeImmutable());

                    if (isset($data['isPublished'])) {
                        $doc->setPublishedAt(new \DateTime());
                    }

                    $em->persist($doc);
                    $em->flush();

                    $this->addFlash('success', 'Article créé avec succès.');
                    return $this->redirectToRoute('app_admin_documents');
                }
            }
        }

        return $this->render('admin/documents/form.html.twig', [
            'document'   => null,
            'categories' => $categories,
            'error'      => $error,
            'mode'       => 'new',
        ]);
    }

    // ─────────────────────────────────────────
    // DOCUMENTS — édition
    // ─────────────────────────────────────────

    #[Route('/documents/{id}/modifier', name: 'app_admin_document_edit', methods: ['GET', 'POST'])]
    public function documentEdit(
        int $id,
        Request $request,
        EntityManagerInterface $em,
        DocumentRepository $docRepo,
        DocumentCategoryRepository $catRepo,
        SluggerInterface $slugger
    ): Response {
        $doc = $docRepo->find($id);
        if (!$doc) {
            throw $this->createNotFoundException('Article introuvable.');
        }

        $categories = $catRepo->findBy([], ['position' => 'ASC']);
        $error = null;

        if ($request->isMethod('POST')) {
            $data = $request->request->all();

            if (empty($data['title']) || empty($data['category'])) {
                $error = 'Le titre et la catégorie sont obligatoires.';
            } else {
                $category = $catRepo->find((int)$data['category']);
                if (!$category) {
                    $error = 'Catégorie introuvable.';
                } else {
                    $doc->setTitle($data['title']);
                    if (!empty($data['slug'])) {
                        $doc->setSlug($data['slug']);
                    }
                    $doc->setContent($data['content'] ?? '');
                    $doc->setExcerpt($data['excerpt'] ?? null);
                    $doc->setAccessLevel($data['accessLevel'] ?? 'free');
                    $doc->setReadingTime(!empty($data['readingTime']) ? (int)$data['readingTime'] : null);
                    $doc->setPosition(!empty($data['position']) ? (int)$data['position'] : $doc->getPosition());
                    $doc->setCategory($category);
                    $doc->setUpdatedAt(new \DateTime());

                    $wasPublished = $doc->isPublished();
                    $doc->setIsPublished(isset($data['isPublished']));

                    if (!$wasPublished && isset($data['isPublished'])) {
                        $doc->setPublishedAt(new \DateTime());
                    }

                    $em->flush();

                    $this->addFlash('success', 'Article mis à jour.');
                    return $this->redirectToRoute('app_admin_documents');
                }
            }
        }

        return $this->render('admin/documents/form.html.twig', [
            'document'   => $doc,
            'categories' => $categories,
            'error'      => $error,
            'mode'       => 'edit',
        ]);
    }

    // ─────────────────────────────────────────
    // DOCUMENTS — suppression
    // ─────────────────────────────────────────

    #[Route('/documents/{id}/supprimer', name: 'app_admin_document_delete', methods: ['POST'])]
    public function documentDelete(
        int $id,
        Request $request,
        EntityManagerInterface $em,
        DocumentRepository $docRepo
    ): Response {
        $doc = $docRepo->find($id);
        if ($doc && $this->isCsrfTokenValid('delete-doc-' . $id, $request->request->get('_token'))) {
            $em->remove($doc);
            $em->flush();
            $this->addFlash('success', 'Article supprimé.');
        }
        return $this->redirectToRoute('app_admin_documents');
    }

    // ─────────────────────────────────────────
    // CATÉGORIES — liste
    // ─────────────────────────────────────────

    #[Route('/categories', name: 'app_admin_categories', methods: ['GET'])]
    public function categories(
        DocumentCategoryRepository $catRepo,
        DocumentRepository $docRepo
    ): Response {
        $categories = $catRepo->findBy([], ['position' => 'ASC']);
        $counts = [];
        foreach ($categories as $cat) {
            $counts[$cat->getId()] = $docRepo->countByCategory($cat);
        }

        // Préparer tous les articles pour le drill-down JS
        $allDocs = [];
        foreach ($docRepo->findBy([], ['position' => 'ASC']) as $doc) {
            $allDocs[] = [
                'id'          => $doc->getId(),
                'catId'       => $doc->getCategory()->getId(),
                'title'       => $doc->getTitle(),
                'excerpt'     => $doc->getExcerpt(),
                'access'      => $doc->getAccessLevel(),
                'readingTime' => $doc->getReadingTime(),
                'published'   => $doc->isPublished(),
                'position'    => $doc->getPosition(),
            ];
        }

        return $this->render('admin/categories/index.html.twig', [
            'categories' => $categories,
            'counts'     => $counts,
            'allDocs'    => $allDocs,
        ]);
    }

    // ─────────────────────────────────────────
    // CATÉGORIES — nouvelle
    // ─────────────────────────────────────────

    #[Route('/categories/nouvelle', name: 'app_admin_category_new', methods: ['GET', 'POST'])]
    public function categoryNew(
        Request $request,
        EntityManagerInterface $em,
        DocumentCategoryRepository $catRepo,
        SluggerInterface $slugger
    ): Response {
        $error = null;

        if ($request->isMethod('POST')) {
            $data = $request->request->all();

            if (empty($data['name'])) {
                $error = 'Le nom est obligatoire.';
            } else {
                // Auto-slug si vide
                $slug = !empty($data['slug'])
                    ? $data['slug']
                    : strtolower($slugger->slug($data['name']));

                // Vérifier unicité du slug
                $existing = $catRepo->findOneBy(['slug' => $slug]);
                if ($existing) {
                    $error = 'Ce slug existe déjà. Choisissez-en un autre.';
                } else {
                    // Auto-position si vide
                    $maxPos = $catRepo->createQueryBuilder('c')
                        ->select('MAX(c.position)')
                        ->getQuery()
                        ->getSingleScalarResult();

                    $cat = new DocumentCategory();
                    $cat->setName($data['name']);
                    $cat->setSlug($slug);
                    $cat->setDescription($data['description'] ?? null);
                    $cat->setIcon($data['icon'] ?? null);
                    $cat->setAccessLevel($data['accessLevel'] ?? 'free');
                    $cat->setPosition(!empty($data['position']) ? (int)$data['position'] : (int)$maxPos + 1);

                    $em->persist($cat);
                    $em->flush();

                    $this->addFlash('success', 'Catégorie "' . $cat->getName() . '" créée.');
                    return $this->redirectToRoute('app_admin_categories');
                }
            }
        }

        return $this->render('admin/categories/form.html.twig', [
            'category' => null,
            'error'    => $error,
            'mode'     => 'new',
        ]);
    }

    // ─────────────────────────────────────────
    // CATÉGORIES — édition
    // ─────────────────────────────────────────

    #[Route('/categories/{id}/modifier', name: 'app_admin_category_edit', methods: ['GET', 'POST'])]
    public function categoryEdit(
        int $id,
        Request $request,
        EntityManagerInterface $em,
        DocumentCategoryRepository $catRepo
    ): Response {
        $cat = $catRepo->find($id);
        if (!$cat) {
            throw $this->createNotFoundException('Catégorie introuvable.');
        }

        $error = null;

        if ($request->isMethod('POST')) {
            $data = $request->request->all();

            if (empty($data['name'])) {
                $error = 'Le nom est obligatoire.';
            } else {
                // Vérifier unicité du slug si modifié
                if (!empty($data['slug']) && $data['slug'] !== $cat->getSlug()) {
                    $existing = $catRepo->findOneBy(['slug' => $data['slug']]);
                    if ($existing) {
                        $error = 'Ce slug est déjà utilisé par une autre catégorie.';
                    }
                }

                if (!$error) {
                    $cat->setName($data['name']);
                    if (!empty($data['slug'])) {
                        $cat->setSlug($data['slug']);
                    }
                    $cat->setDescription($data['description'] ?? null);
                    $cat->setIcon($data['icon'] ?? null);
                    $cat->setAccessLevel($data['accessLevel'] ?? 'free');
                    $cat->setPosition(!empty($data['position']) ? (int)$data['position'] : $cat->getPosition());

                    $em->flush();

                    $this->addFlash('success', 'Catégorie mise à jour.');
                    return $this->redirectToRoute('app_admin_categories');
                }
            }
        }

        return $this->render('admin/categories/form.html.twig', [
            'category' => $cat,
            'error'    => $error,
            'mode'     => 'edit',
        ]);
    }

    // ─────────────────────────────────────────
    // CATÉGORIES — suppression
    // ─────────────────────────────────────────

    #[Route('/categories/{id}/supprimer', name: 'app_admin_category_delete', methods: ['POST'])]
    public function categoryDelete(
        int $id,
        Request $request,
        EntityManagerInterface $em,
        DocumentCategoryRepository $catRepo,
        DocumentRepository $docRepo
    ): Response {
        $cat = $catRepo->find($id);
        if ($cat && $this->isCsrfTokenValid('delete-cat-' . $id, $request->request->get('_token'))) {
            // Bloquer si la catégorie contient des articles
            $count = $docRepo->count(['category' => $cat]);
            if ($count > 0) {
                $this->addFlash('danger', 'Impossible de supprimer : ' . $count . ' article(s) dans cette catégorie. Déplacez-les d\'abord.');
            } else {
                $em->remove($cat);
                $em->flush();
                $this->addFlash('success', 'Catégorie supprimée.');
            }
        }
        return $this->redirectToRoute('app_admin_categories');
    }

    // ─────────────────────────────────────────
    // DOCUMENTS — réordonnancement AJAX
    // ─────────────────────────────────────────

    #[Route('/documents/reorder', name: 'app_admin_documents_reorder', methods: ['POST'])]
    public function documentsReorder(
        Request $request,
        EntityManagerInterface $em,
        DocumentRepository $docRepo
    ): Response {
        $data = json_decode($request->getContent(), true);
        $ids  = $data['ids'] ?? [];

        foreach ($ids as $position => $id) {
            $doc = $docRepo->find((int)$id);
            if ($doc) {
                $doc->setPosition($position + 1);
            }
        }

        $em->flush();

        return $this->json(['success' => true]);
    }

    // ─────────────────────────────────────────
    // CATÉGORIES — réordonnancement AJAX
    // ─────────────────────────────────────────

    #[Route('/categories/reorder', name: 'app_admin_categories_reorder', methods: ['POST'])]
    public function categoriesReorder(
        Request $request,
        EntityManagerInterface $em,
        DocumentCategoryRepository $catRepo
    ): Response {
        $data = json_decode($request->getContent(), true);
        $ids  = $data['ids'] ?? [];

        foreach ($ids as $position => $id) {
            $cat = $catRepo->find((int)$id);
            if ($cat) {
                $cat->setPosition($position + 1);
            }
        }

        $em->flush();

        return $this->json(['success' => true]);
    }

    // ─────────────────────────────────────────
    // PROJETS — liste
    // ─────────────────────────────────────────

    #[Route('/projets', name: 'app_admin_projects', methods: ['GET'])]
    public function projects(ProjectRepository $projectRepo): Response
    {
        return $this->render('admin/projects/index.html.twig', [
            'projects' => $projectRepo->findBy([], ['position' => 'ASC']),
        ]);
    }

    // ─────────────────────────────────────────
    // PROJETS — nouveau
    // ─────────────────────────────────────────

    #[Route('/projets/nouveau', name: 'app_admin_project_new', methods: ['GET', 'POST'])]
    public function projectNew(
        Request $request,
        EntityManagerInterface $em,
        SluggerInterface $slugger
    ): Response {
        $error = null;

        if ($request->isMethod('POST')) {
            $data = $request->request->all();

            if (empty($data['title'])) {
                $error = 'Le titre est obligatoire.';
            } else {
                $project = new Project();
                $project->setTitle($data['title']);
                $project->setSlug(strtolower($slugger->slug($data['title'])));
                $project->setType($data['type'] ?? 'fullstack');
                $project->setShortDesc($data['shortDesc'] ?? '');
                $project->setDescription($data['description'] ?? '');
                $project->setTechStack(
                    array_filter(array_map('trim', explode(',', $data['techStack'] ?? '')))
                );
                $project->setImageUrl($data['imageUrl'] ?? null);
                $project->setProjectUrl($data['projectUrl'] ?? null);
                $project->setGithubUrl($data['githubUrl'] ?? null);
                $project->setIsFeatured(isset($data['isFeatured']));
                $project->setIsVisible(isset($data['isVisible']));
                $project->setPosition((int)($data['position'] ?? 0));
                $project->setCreatedAt(new \DateTimeImmutable());

                $em->persist($project);
                $em->flush();

                $this->addFlash('success', 'Projet créé avec succès.');
                return $this->redirectToRoute('app_admin_projects');
            }
        }

        return $this->render('admin/projects/form.html.twig', [
            'project' => null,
            'error'   => $error,
            'mode'    => 'new',
        ]);
    }

    // ─────────────────────────────────────────
    // PROJETS — édition
    // ─────────────────────────────────────────

    #[Route('/projets/{id}/modifier', name: 'app_admin_project_edit', methods: ['GET', 'POST'])]
    public function projectEdit(
        int $id,
        Request $request,
        EntityManagerInterface $em,
        ProjectRepository $projectRepo,
        SluggerInterface $slugger
    ): Response {
        $project = $projectRepo->find($id);
        if (!$project) {
            throw $this->createNotFoundException('Projet introuvable.');
        }

        $error = null;

        if ($request->isMethod('POST')) {
            $data = $request->request->all();

            if (empty($data['title'])) {
                $error = 'Le titre est obligatoire.';
            } else {
                $project->setTitle($data['title']);
                if (!empty($data['slug'])) {
                    $project->setSlug($data['slug']);
                }
                $project->setType($data['type'] ?? 'fullstack');
                $project->setShortDesc($data['shortDesc'] ?? '');
                $project->setDescription($data['description'] ?? '');
                $project->setTechStack(
                    array_filter(array_map('trim', explode(',', $data['techStack'] ?? '')))
                );
                $project->setImageUrl($data['imageUrl'] ?? null);
                $project->setProjectUrl($data['projectUrl'] ?? null);
                $project->setGithubUrl($data['githubUrl'] ?? null);
                $project->setIsFeatured(isset($data['isFeatured']));
                $project->setIsVisible(isset($data['isVisible']));
                $project->setPosition((int)($data['position'] ?? 0));

                $em->flush();

                $this->addFlash('success', 'Projet mis à jour.');
                return $this->redirectToRoute('app_admin_projects');
            }
        }

        return $this->render('admin/projects/form.html.twig', [
            'project' => $project,
            'error'   => $error,
            'mode'    => 'edit',
        ]);
    }

    // ─────────────────────────────────────────
    // WAITLIST
    // ─────────────────────────────────────────

    #[Route('/waitlist', name: 'app_admin_waitlist', methods: ['GET'])]
    public function waitlist(WaitlistEntryRepository $waitlistRepo): Response
    {
        return $this->render('admin/waitlist/index.html.twig', [
            'entries' => $waitlistRepo->findBy([], ['createdAt' => 'DESC']),
        ]);
    }

    // ─────────────────────────────────────────
    // UTILISATEURS
    // ─────────────────────────────────────────

    #[Route('/utilisateurs', name: 'app_admin_users', methods: ['GET'])]
    public function users(UserRepository $userRepo): Response
    {
        return $this->render('admin/users/index.html.twig', [
            'users' => $userRepo->findBy([], ['createdAt' => 'DESC']),
        ]);
    }

    #[Route('/utilisateurs/{id}/premium', name: 'app_admin_user_toggle_premium', methods: ['POST'])]
    public function togglePremium(
        int $id,
        Request $request,
        EntityManagerInterface $em,
        UserRepository $userRepo
    ): Response {
        $user = $userRepo->find($id);
        if ($user && $this->isCsrfTokenValid('toggle-premium-' . $id, $request->request->get('_token'))) {
            $user->setIsPremium(!$user->isPremium());
            $em->flush();
            $this->addFlash('success', $user->getFirstName() . ' est maintenant ' . ($user->isPremium() ? 'Premium' : 'gratuit') . '.');
        }
        return $this->redirectToRoute('app_admin_users');
    }
}