<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\WaitlistEntry;

/**
 * Export CSV de la liste d'attente (séparateur « ; » et BOM UTF-8 pour Excel en français).
 * Les cellules commençant par = + - @ sont neutralisées : à l'ouverture dans un tableur,
 * une inscription malveillante ne peut pas devenir une formule (injection CSV).
 */
final class WaitlistCsv
{
    /** @param iterable<WaitlistEntry> $entries */
    public function build(iterable $entries): string
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, ['E-mail', 'Prénom', 'Source', 'Inscription', 'Consentement', 'Prévenu'], ';', '"', '');
        foreach ($entries as $entry) {
            fputcsv($handle, array_map($this->cell(...), [
                $entry->getEmail(),
                $entry->getFirstName(),
                $entry->getSource(),
                $entry->getCreatedAt()?->format('Y-m-d H:i'),
                $entry->getConsentAt()?->format('Y-m-d H:i') ?? 'non recueilli',
                $entry->isNotified() ? 'oui' : 'non',
            ]), ';', '"', '');
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    private function cell(?string $value): string
    {
        $value = (string) $value;

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'" . $value : $value;
    }
}
