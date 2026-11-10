<?php

declare(strict_types=1);

namespace App\Service\Community;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Contrôle et réencodage des captures envoyées par les membres.
 *
 * - type réel vérifié (contenu du fichier), extension et taille contrôlées ;
 * - image redécodée puis réencodée en WebP (JPEG à défaut) : les métadonnées (EXIF, position GPS,
 *   modèle d'appareil) et tout contenu caché dans le fichier d'origine disparaissent ;
 * - largeur ramenée à 1 600 px au plus. Le fichier d'origine n'est jamais conservé.
 */
final class TradeImageProcessor
{
    public const MAX_OCTETS = 4 * 1024 * 1024;
    private const TYPES = ['image/png' => ['png'], 'image/jpeg' => ['jpg', 'jpeg'], 'image/webp' => ['webp']];
    private const LARGEUR_MAX = 1600;
    private const PIXELS_MAX = 40_000_000;

    public function isAvailable(): bool
    {
        return function_exists('imagecreatefromstring') && (function_exists('imagewebp') || function_exists('imagejpeg'));
    }

    /**
     * @return array{data: string, mime: string, width: int, height: int}
     *
     * @throws \InvalidArgumentException message affichable au membre
     */
    public function process(UploadedFile $file): array
    {
        if (!$this->isAvailable()) {
            throw new \InvalidArgumentException('L\'envoi d\'images est momentanément indisponible.');
        }
        if (!$file->isValid() || $file->getSize() === false || $file->getSize() > self::MAX_OCTETS) {
            throw new \InvalidArgumentException('Chaque capture doit faire 4 Mo au plus.');
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file->getPathname());
        $extension = strtolower($file->getClientOriginalExtension());
        if (!isset(self::TYPES[$mime]) || !in_array($extension, self::TYPES[$mime], true)) {
            throw new \InvalidArgumentException('Formats acceptés : PNG, JPEG ou WebP.');
        }
        $infos = @getimagesize($file->getPathname());
        if ($infos === false || $infos[0] < 50 || $infos[1] < 50 || $infos[0] * $infos[1] > self::PIXELS_MAX) {
            throw new \InvalidArgumentException('Image illisible ou de dimensions inhabituelles.');
        }
        $source = @imagecreatefromstring((string) file_get_contents($file->getPathname()));
        if ($source === false) {
            throw new \InvalidArgumentException('Image illisible.');
        }
        $largeur = imagesx($source);
        $hauteur = imagesy($source);
        if ($largeur > self::LARGEUR_MAX) {
            $hauteur = (int) round($hauteur * self::LARGEUR_MAX / $largeur);
            $largeur = self::LARGEUR_MAX;
        }
        $image = imagecreatetruecolor($largeur, $hauteur);
        // Fond blanc sous les zones transparentes (sinon elles deviendraient noires).
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 255, 255, 255));
        imagecopyresampled($image, $source, 0, 0, 0, 0, $largeur, $hauteur, imagesx($source), imagesy($source));
        imagedestroy($source);

        ob_start();
        if (function_exists('imagewebp')) {
            imagewebp($image, null, 82);
            $type = 'image/webp';
        } else {
            imagejpeg($image, null, 85);
            $type = 'image/jpeg';
        }
        $data = (string) ob_get_clean();
        imagedestroy($image);

        return ['data' => $data, 'mime' => $type, 'width' => $largeur, 'height' => $hauteur];
    }
}
