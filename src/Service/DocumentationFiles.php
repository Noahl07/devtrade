<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

/**
 * Articles de documentation sous forme de fichiers versionnés (content/documentation/<module>/<article>.html).
 * Chaque fichier commence par un commentaire JSON de métadonnées, suivi du contenu HTML de l'article.
 *
 * export : base → fichiers (sauvegarde et point de départ de la réécriture, relisible en git diff).
 * apply  : fichiers → base, uniquement pour les articles existants (aucune création, aucune suppression).
 */
final class DocumentationFiles
{
    private const META_START = '<!--meta';
    private const META_END = '-->';
    private const EDITABLE = ['title', 'excerpt', 'readingTime', 'accessLevel'];

    public function __construct(
        private readonly Connection $db,
        #[Autowire('%kernel.project_dir%/content/documentation')] private readonly string $directory,
    ) {
    }

    public function directory(): string
    {
        return $this->directory;
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
                'module' => $row['module'],
            ];
            $file = sprintf('%s/%02d-%s/%s.html', $this->directory, (int) $row['module_position'], $row['module'], $row['slug']);
            $fs->dumpFile($file, self::META_START . "\n" . json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n" . self::META_END . "\n" . rtrim(str_replace("\r\n", "\n", (string) $row['content'])) . "\n");
        }

        return count($rows);
    }

    /**
     * @return list<array{slug: string, file: string, fields: array<string, array{0: mixed, 1: mixed}>, missing?: bool}>
     */
    public function diff(): array
    {
        if (!is_dir($this->directory)) {
            return [];
        }
        $current = array_column($this->db->fetchAllAssociative('SELECT slug, title, excerpt, reading_time AS "readingTime", access_level AS "accessLevel", content FROM document'), null, 'slug');
        $changes = [];

        foreach ((new Finder())->files()->in($this->directory)->name('*.html')->sortByName() as $file) {
            [$meta, $content] = $this->read($file->getPathname());
            $slug = (string) ($meta['slug'] ?? '');
            $relative = $file->getRelativePathname();
            if (!isset($current[$slug])) {
                $changes[] = ['slug' => $slug, 'file' => $relative, 'fields' => [], 'missing' => true];
                continue;
            }
            $fields = [];
            foreach (self::EDITABLE as $field) {
                if (array_key_exists($field, $meta) && (string) $meta[$field] !== (string) $current[$slug][$field]) {
                    $fields[$field] = [$current[$slug][$field], $meta[$field]];
                }
            }
            if ($this->normalize($content) !== $this->normalize((string) $current[$slug]['content'])) {
                $fields['content'] = [(string) $current[$slug]['content'], $content];
            }
            if ($fields !== []) {
                $changes[] = ['slug' => $slug, 'file' => $relative, 'fields' => $fields];
            }
        }

        return $changes;
    }

    /** @param list<array{slug: string, fields: array<string, array{0: mixed, 1: mixed}>, missing?: bool}> $changes */
    public function apply(array $changes): int
    {
        $columns = ['title' => 'title', 'excerpt' => 'excerpt', 'readingTime' => 'reading_time', 'accessLevel' => 'access_level', 'content' => 'content'];
        $count = 0;
        $this->db->transactional(function (Connection $db) use ($changes, $columns, &$count): void {
            foreach ($changes as $change) {
                if (($change['missing'] ?? false) || $change['fields'] === []) {
                    continue;
                }
                $data = ['updated_at' => (new \DateTime())->format('Y-m-d H:i:s')];
                foreach ($change['fields'] as $field => [, $new]) {
                    $data[$columns[$field]] = $new;
                }
                $count += $db->update('document', $data, ['slug' => $change['slug']]);
            }
        });

        return $count;
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
        if (isset($meta['accessLevel']) && !in_array($meta['accessLevel'], ['free', 'premium'], true)) {
            throw new \RuntimeException(sprintf('%s : niveau d\'accès « %s » non géré.', $path, $meta['accessLevel']));
        }

        return [$meta, rtrim(substr($raw, $end + strlen(self::META_END) + 1))];
    }

    private function normalize(string $html): string
    {
        return rtrim(str_replace("\r\n", "\n", $html));
    }
}
