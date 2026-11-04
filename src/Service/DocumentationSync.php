<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

/**
 * Compare la documentation (modules et articles) d'une autre base locale avec la base de travail, sur le slug,
 * et importe ce qui n'existe que dans la source. N'écrase et ne supprime jamais rien dans la base de travail.
 *
 * La base source est ouverte avec les paramètres de la connexion configurée, seul le nom de base change :
 * aucun identifiant n'est lu ni affiché.
 */
final class DocumentationSync
{
    /** Champs comparés pour signaler un conflit (même slug, contenu différent). */
    private const CATEGORY_FIELDS = ['name', 'description', 'icon', 'access_level'];
    private const DOCUMENT_FIELDS = ['title', 'content', 'excerpt', 'access_level', 'reading_time', 'is_published', 'category_slug'];

    public function __construct(private readonly Connection $target)
    {
    }

    public function targetDatabase(): string
    {
        return (string) $this->target->getDatabase();
    }

    public function openSource(string $database): Connection
    {
        $params = $this->target->getParams();
        unset($params['url']);
        $params['dbname'] = $database;

        return DriverManager::getConnection($params);
    }

    /**
     * @return array{
     *   newCategories: list<array<string, mixed>>,
     *   newDocuments: list<array<string, mixed>>,
     *   categoryConflicts: list<array{slug: string, fields: list<string>}>,
     *   documentConflicts: list<array{slug: string, fields: list<string>}>,
     *   titleDuplicates: list<array{type: string, sourceSlug: string, targetSlug: string, title: string}>,
     *   identical: array{categories: int, documents: int},
     * }
     */
    public function diff(Connection $source, bool $includeTitleDuplicates = false): array
    {
        $sourceCategories = $this->categories($source);
        $targetCategories = $this->categories($this->target);
        $sourceDocuments = $this->documents($source);
        $targetDocuments = $this->documents($this->target);

        $plan = [
            'newCategories' => [],
            'newDocuments' => [],
            'categoryConflicts' => [],
            'documentConflicts' => [],
            'titleDuplicates' => [],
            'identical' => ['categories' => 0, 'documents' => 0],
        ];

        // Même titre (ou nom de module), slug différent : très probablement le même contenu.
        $targetCategoryByName = $this->indexByTitle($targetCategories, 'name');
        $targetDocumentByTitle = $this->indexByTitle($targetDocuments, 'title');
        // Module de rattachement dans la base de travail, pour chaque module source.
        $categoryMap = [];

        foreach ($sourceCategories as $slug => $row) {
            $slug = (string) $slug;
            $twin = $targetCategoryByName[$this->normalizeTitle((string) $row['name'])] ?? null;
            if (!isset($targetCategories[$slug]) && $twin !== null && !$includeTitleDuplicates) {
                $plan['titleDuplicates'][] = ['type' => 'module', 'sourceSlug' => $slug, 'targetSlug' => $twin, 'title' => (string) $row['name']];
                $categoryMap[$slug] = $twin;
                continue;
            }
            $categoryMap[$slug] = $slug;
            if (!isset($targetCategories[$slug])) {
                $plan['newCategories'][] = $row;
            } elseif ($fields = $this->differences($row, $targetCategories[$slug], self::CATEGORY_FIELDS)) {
                $plan['categoryConflicts'][] = ['slug' => (string) $slug, 'fields' => $fields];
            } else {
                ++$plan['identical']['categories'];
            }
        }

        foreach ($sourceDocuments as $slug => $row) {
            $slug = (string) $slug;
            $twin = $targetDocumentByTitle[$this->normalizeTitle((string) $row['title'])] ?? null;
            if (!isset($targetDocuments[$slug]) && $twin !== null && !$includeTitleDuplicates) {
                $plan['titleDuplicates'][] = ['type' => 'article', 'sourceSlug' => $slug, 'targetSlug' => $twin, 'title' => (string) $row['title']];
                continue;
            }
            if (!isset($targetDocuments[$slug])) {
                $row['target_category_slug'] = $categoryMap[$row['category_slug']] ?? $row['category_slug'];
                $plan['newDocuments'][] = $row;
            } elseif ($fields = $this->differences($row, $targetDocuments[$slug], self::DOCUMENT_FIELDS)) {
                $plan['documentConflicts'][] = ['slug' => (string) $slug, 'fields' => $fields];
            } else {
                ++$plan['identical']['documents'];
            }
        }

        return $plan;
    }

    /**
     * Insère les nouveaux modules puis les nouveaux articles, dans une seule transaction.
     * L'auteur est rattaché par e-mail s'il existe dans la base de travail, sinon laissé vide.
     *
     * @param array{newCategories: list<array<string, mixed>>, newDocuments: list<array<string, mixed>>} $plan
     *
     * @return array{categories: int, documents: int, authorsUnmatched: int}
     */
    public function import(array $plan): array
    {
        $result = ['categories' => 0, 'documents' => 0, 'authorsUnmatched' => 0];

        $this->target->transactional(function (Connection $db) use ($plan, &$result): void {
            foreach ($plan['newCategories'] as $row) {
                $db->insert('document_category', [
                    'name' => $row['name'],
                    'slug' => $row['slug'],
                    'description' => $row['description'],
                    'icon' => $row['icon'],
                    'position' => (int) $row['position'],
                    'access_level' => $row['access_level'],
                ]);
                ++$result['categories'];
            }

            $categoryIds = array_column(
                $db->fetchAllAssociative('SELECT id, slug FROM document_category'),
                'id',
                'slug',
            );

            foreach ($plan['newDocuments'] as $row) {
                $authorId = null;
                if ($row['author_email'] !== null) {
                    $authorId = $db->fetchOne('SELECT id FROM "user" WHERE email = ?', [$row['author_email']]) ?: null;
                    if ($authorId === null) {
                        ++$result['authorsUnmatched'];
                    }
                }
                $db->insert('document', [
                    'title' => $row['title'],
                    'slug' => $row['slug'],
                    'content' => $row['content'],
                    'excerpt' => $row['excerpt'],
                    'access_level' => $row['access_level'],
                    'reading_time' => $row['reading_time'],
                    'is_published' => $row['is_published'],
                    'published_at' => $row['published_at'],
                    'created_at' => $row['created_at'],
                    'updated_at' => $row['updated_at'],
                    'position' => (int) $row['position'],
                    'category_id' => $categoryIds[$row['target_category_slug']] ?? throw new \RuntimeException(sprintf('Module « %s » introuvable pour l\'article « %s ».', $row['target_category_slug'], $row['slug'])),
                    'author_id' => $authorId,
                ], ['is_published' => 'boolean']);
                ++$result['documents'];
            }
        });

        return $result;
    }

    /** @return array<string, array<string, mixed>> indexé par slug */
    private function categories(Connection $db): array
    {
        $rows = $db->fetchAllAssociative('SELECT name, slug, description, icon, position, access_level FROM document_category ORDER BY position, id');

        return array_column($rows, null, 'slug');
    }

    /** @return array<string, array<string, mixed>> indexé par slug */
    private function documents(Connection $db): array
    {
        $rows = $db->fetchAllAssociative(<<<'SQL'
            SELECT d.title, d.slug, d.content, d.excerpt, d.access_level, d.reading_time, d.is_published,
                   d.published_at, d.created_at, d.updated_at, d.position, c.slug AS category_slug, u.email AS author_email
            FROM document d
            JOIN document_category c ON c.id = d.category_id
            LEFT JOIN "user" u ON u.id = d.author_id
            ORDER BY c.position, d.position, d.id
            SQL);

        return array_column($rows, null, 'slug');
    }

    /**
     * @param array<string, array<string, mixed>> $rows indexés par slug
     *
     * @return array<string, string> titre normalisé => slug
     */
    private function indexByTitle(array $rows, string $field): array
    {
        $index = [];
        foreach ($rows as $slug => $row) {
            $index[$this->normalizeTitle((string) $row[$field])] ??= (string) $slug;
        }

        return $index;
    }

    /** Minuscules, sans accents ni ponctuation : « Gestion du risque — les fondamentaux » = « gestion du risque les fondamentaux ». */
    private function normalizeTitle(string $title): string
    {
        $ascii = (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', mb_strtolower($title));

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', strtolower($ascii)));
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     * @param list<string>         $fields
     *
     * @return list<string>
     */
    private function differences(array $a, array $b, array $fields): array
    {
        $normalize = static fn (mixed $v): string => $v === null ? '' : str_replace("\r\n", "\n", trim((string) $v));

        return array_values(array_filter($fields, static fn (string $f): bool => $normalize($a[$f] ?? null) !== $normalize($b[$f] ?? null)));
    }
}
