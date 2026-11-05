<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Service\DocumentationBundle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Envoi du lot de documentation dans l'admin : contrôle du fichier, stockage temporaire hors de public/
 * sous un nom aléatoire, relecture au moment de la confirmation. Les envois de plus d'une heure sont effacés.
 */
final class DocumentationImport
{
    private const MIME_TYPES = ['application/gzip', 'application/x-gzip', 'application/json', 'text/plain'];
    private const EXTENSIONS = ['gz', 'json'];
    private const TTL_SECONDS = 3600;

    public function __construct(
        private readonly DocumentationBundle $bundle,
        #[Autowire('%kernel.project_dir%/var/documentation-import')] private readonly string $directory,
    ) {
    }

    /**
     * Valide l'envoi et le conserve pour la confirmation.
     *
     * @return array{token: string, bundle: array<string, mixed>}
     */
    public function store(?UploadedFile $file): array
    {
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            throw new \InvalidArgumentException('Aucun fichier reçu, ou envoi interrompu.');
        }
        if ($file->getSize() > DocumentationBundle::MAX_COMPRESSED_BYTES) {
            throw new \InvalidArgumentException('Fichier trop volumineux (8 Mo maximum).');
        }
        if (!in_array(strtolower($file->getClientOriginalExtension()), self::EXTENSIONS, true)
            || !in_array($file->getMimeType(), self::MIME_TYPES, true)) {
            throw new \InvalidArgumentException('Type de fichier refusé : envoyez le fichier .json.gz produit par la commande « bundle ».');
        }

        $raw = (string) file_get_contents($file->getPathname());
        $bundle = $this->bundle->decode($raw);

        $this->purge();
        $token = bin2hex(random_bytes(16));
        (new Filesystem())->dumpFile($this->path($token), $raw);

        return ['token' => $token, 'bundle' => $bundle];
    }

    /** @return array<string, mixed> */
    public function load(string $token): array
    {
        $path = $this->path($token);
        if (!is_file($path) || filemtime($path) < time() - self::TTL_SECONDS) {
            throw new \InvalidArgumentException('Cet envoi a expiré. Renvoyez le fichier.');
        }

        return $this->bundle->decode((string) file_get_contents($path));
    }

    public function forget(string $token): void
    {
        (new Filesystem())->remove($this->path($token));
    }

    private function path(string $token): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            throw new \InvalidArgumentException('Envoi inconnu.');
        }

        return $this->directory . '/' . $token . '.bin';
    }

    private function purge(): void
    {
        foreach (glob($this->directory . '/*.bin') ?: [] as $old) {
            if (filemtime($old) < time() - self::TTL_SECONDS) {
                @unlink($old);
            }
        }
    }
}
