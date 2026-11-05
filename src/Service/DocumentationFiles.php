<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

/**
 * Articles de documentation sous forme de fichiers (<racine>/<NN-module>/<article>.html).
 * Chaque fichier commence par un commentaire JSON de métadonnées, suivi du contenu HTML de l'article.
 *
 * Deux racines : content/documentation (articles gratuits, versionnés dans git) et
 * content/premium (articles Premium, ignorés par git car le dépôt est public).
 *
 * export : base → fichiers. bundle : fichiers → lot d'import (voir DocumentationBundle).
 * Ordre : « position » dans les métadonnées pour l'article, préfixe NN- du dossier pour le module.
 */
final class DocumentationFiles
{
    private const META_START = '<!--meta';
    private const META_END = '-->';
    public const PUBLIC_DIR = 'documentation';
    public const PREMIUM_DIR = 'premium';

    public function __construct(
        private readonly Connection $db,
        #[Autowire('%kernel.project_dir%/content')] private readonly string $root,
    ) {
    }

    public function directory(): string
    {
        return $this->root;
    }

    public function export(): int
    {
        $fs = new Filesystem();
        $rows = $this->db->fetchAllAssociative(<<<'SQL'
            SELECT d.slug, d.title, d.excerpt, d.reading_time, d.access_level, d.content, d.position,
                   c.slug AS module, c.position AS module_position
            FROM document d JOIN document_category c ON c.id = d.category_id
            ORDER BY c.position, d.position, d.id
            SQL);

        foreach ($rows as $row) {
            $meta = [
                'slug' => $row['slug'],
                'title' => $row['title'],
                'excerpt' => $row['excerpt'],
                'readingTime' => $row['reading_time'] !== null ? (int) $row['reading_time'] : null,
                'accessLevel' => $row['access_level'],
                'position' => (int) $row['position'],
                'module' => $row['module'],
            ];
            $dir = $row['access_level'] === 'premium' ? self::PREMIUM_DIR : self::PUBLIC_DIR;
            $file = sprintf('%s/%s/%02d-%s/%s.html', $this->root, $dir, (int) $row['module_position'], $row['module'], $row['slug']);
            $fs->dumpFile($file, self::META_START . "\n" . json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n" . self::META_END . "\n" . rtrim(str_replace("\r\n", "\n", (string) $row['content'])) . "\n");
        }

        return count($rows);
    }

    /**
     * Tous les articles des deux racines.
     *
     * @return list<array{file: string, modulePosition: int, meta: array<string, mixed>, content: string}>
     */
    public function entries(): array
    {
        $entries = [];
        foreach ([self::PUBLIC_DIR, self::PREMIUM_DIR] as $dir) {
            $base = $this->root . '/' . $dir;
            if (!is_dir($base)) {
                continue;
            }
            foreach ((new Finder())->files()->in($base)->name('*.html')->sortByName() as $file) {
                [$meta, $content] = $this->read($file->getPathname());
                $moduleDir = basename($file->getPath());
                if (!preg_match('/^(\d+)-(.+)$/', $moduleDir, $m) || ($meta['module'] ?? null) !== $m[2]) {
                    throw new \RuntimeException(sprintf('%s : le dossier « %s » ne correspond pas au module « %s ».', $file->getPathname(), $moduleDir, $meta['module'] ?? '?'));
                }
                $isPremium = ($meta['accessLevel'] ?? 'free') === 'premium';
                if ($isPremium !== ($dir === self::PREMIUM_DIR)) {
                    throw new \RuntimeException(sprintf('%s : un article %s doit être rangé dans content/%s.', $file->getPathname(), $isPremium ? 'Premium' : 'gratuit', $isPremium ? self::PREMIUM_DIR : self::PUBLIC_DIR));
                }
                $entries[] = [
                    'file' => $dir . '/' . $file->getRelativePathname(),
                    'modulePosition' => (int) $m[1],
                    'meta' => $meta,
                    'content' => $content,
                ];
            }
        }

        return $entries;
    }

    /**
     * Lot d'import construit depuis les fichiers ; les métadonnées des modules (nom, description…) viennent de la base locale.
     *
     * @return array<string, mixed>
     */
    public function bundle(): array
    {
        $entries = $this->entries();
        $categories = array_column($this->db->fetchAllAssociative('SELECT slug, name, description, icon, access_level FROM document_category'), null, 'slug');
        $published = array_column($this->db->fetchAllAssociative('SELECT slug, is_published FROM document'), 'is_published', 'slug');

        $modules = [];
        $articles = [];
        foreach ($entries as $entry) {
            $slug = (string) $entry['meta']['module'];
            if (!isset($categories[$slug])) {
                throw new \RuntimeException(sprintf('Module « %s » absent de la base locale.', $slug));
            }
            $modules[$slug] = [
                'slug' => $slug,
                'name' => $categories[$slug]['name'],
                'description' => $categories[$slug]['description'],
                'icon' => $categories[$slug]['icon'],
                'accessLevel' => $categories[$slug]['access_level'],
                'position' => $entry['modulePosition'],
            ];
            $articles[] = $entry['meta'] + [
                'content' => $entry['content'],
                'published' => (bool) ($published[$entry['meta']['slug']] ?? true),
            ];
        }

        return [
            'format' => DocumentationBundle::FORMAT,
            'version' => DocumentationBundle::VERSION,
            'generatedAt' => (new \DateTimeImmutable())->format(\DATE_ATOM),
            'modules' => array_values($modules),
            'articles' => $articles,
        ];
    }

    /** @return array{0: array<string, mixed>, 1: string} */
    private function read(string $path): array
    {
        $raw = str_replace("\r\n", "\n", (string) file_get_contents($path));
        if (!str_starts_with($raw, self::META_START)) {
            throw new \RuntimeException(sprintf('%s : en-tête de métadonnées manquant.', $path));
        }
        $end = strpos($raw, self::META_END);
        $meta = json_decode(trim(substr($raw, strlen(self::META_START), $end - strlen(self::META_START))), true, 8, JSON_THROW_ON_ERROR);

        return [$meta, rtrim(substr($raw, $end + strlen(self::META_END) + 1))];
    }
}
