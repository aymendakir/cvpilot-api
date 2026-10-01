<?php

namespace App\Services\Ats\Parsing;

use ZipArchive;

/**
 * The real type from the file's bytes, never from its name or MIME type (§5.1). Plain text is only
 * accepted for a `.txt` (or extension-less) upload, so a binary with a text-like head is still refused.
 */
final class DocumentTypeDetector
{
    /** @return 'pdf'|'docx'|'text'|null */
    public function detect(string $path, string $clientName = ''): ?string
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }
        $head = (string) file_get_contents($path, false, null, 0, 1024);
        if (str_contains($head, '%PDF-')) {
            return 'pdf';
        }
        if (str_starts_with($head, "PK\x03\x04")) {
            $zip = new ZipArchive;
            if ($zip->open($path) !== true) {
                return null;
            }
            $ok = $zip->locateName('word/document.xml') !== false && $zip->locateName('[Content_Types].xml') !== false;
            $zip->close();

            return $ok ? 'docx' : null;
        }
        if ($head === '' || str_contains($head, "\0")) {
            return null;
        }
        if (! mb_check_encoding($head, 'UTF-8') && ! mb_check_encoding($head, 'ISO-8859-1')) {
            return null;
        }

        return in_array(strtolower(pathinfo($clientName, PATHINFO_EXTENSION)), ['txt', ''], true) ? 'text' : null;
    }
}
