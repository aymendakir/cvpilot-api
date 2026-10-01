<?php

namespace App\Services;

use App\Services\Ats\Parsing\PdfParser;
use App\Services\Ats\Parsing\UnreadableDocument;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpWord\IOFactory;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class DocumentExtractor
{
    const MAX_KILOBYTES = 15360;

    /** Real type from the file bytes, never from the client-supplied name or MIME. */
    public function detect(UploadedFile $file): ?string
    {
        $path = $file->getRealPath();
        if (! $path || ! is_readable($path)) {
            return null;
        }
        $h = fopen($path, 'rb');
        if (! $h) {
            return null;
        }$head = (string) fread($h, 1024);
        fclose($h);
        if (str_contains($head, '%PDF-')) {
            return 'pdf';
        }
        if (str_starts_with($head, "PK\x03\x04")) {
            $zip = new \ZipArchive;
            if ($zip->open($path) === true) {
                $ok = $zip->locateName('word/document.xml') !== false && $zip->locateName('[Content_Types].xml') !== false;
                $zip->close();
                if ($ok) {
                    return 'docx';
                }
            }

            return null;
        }
        if ($head === '' || str_contains($head, "\0")) {
            return null;
        }
        if (! mb_check_encoding($head, 'UTF-8') && ! mb_check_encoding($head, 'ISO-8859-1')) {
            return null;
        }
        $extension = strtolower($file->getClientOriginalExtension());

        return in_array($extension, ['txt', ''], true) ? 'txt' : null;
    }

    /**
     * Extracts the text and always deletes PHP's temp copy of the upload afterwards, accepted or rejected.
     * The original is never stored anywhere (SPEC S6).
     */
    public function extractAndDiscard(UploadedFile $file): string
    {
        $path = $file->getRealPath();

        try {
            return $this->extract($file);
        } finally {
            if ($path && is_file($path)) {
                @unlink($path);
            }
        }
    }

    public function extract(UploadedFile $file): string
    {
        $type = $this->detect($file);
        abort_if($type === null, 422, 'Unsupported document. Upload a PDF, DOCX or TXT file.');
        try {
            if ($type === 'txt') {
                $text = (string) file_get_contents($file->getRealPath());
                if (! mb_check_encoding($text, 'UTF-8')) {
                    $text = mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1');
                }
            } elseif ($type === 'pdf') {
                // poppler, shared with the ATS checker (docs/ats-spike-s0.md). A missing binary is a
                // server fault (500), not the user's file, so only UnreadableDocument becomes a 422 below.
                $text = app(PdfParser::class)->parse($file->getRealPath())->text;
            } else {
                $doc = IOFactory::load($file->getRealPath());
                $parts = [];
                foreach ($doc->getSections() as $section) {
                    foreach ($section->getElements() as $element) {
                        if (method_exists($element, 'getText')) {
                            $parts[] = $element->getText();
                        }
                    }
                }$text = implode("\n", $parts);
            }
        } catch (UnreadableDocument $e) {
            abort_if($e->reason === UnreadableDocument::PASSWORD_PROTECTED, 422, 'This PDF is password protected. Upload an unlocked copy.');
            abort(422, 'This document could not be read. Export a fresh text-based PDF or DOCX and try again.');
        } catch (\Throwable $e) {
            if ($type === 'pdf' && ! $e instanceof HttpExceptionInterface) {
                throw $e;
            }
            abort(422, 'This document could not be read. Export a fresh text-based PDF or DOCX and try again.');
        }
        $text = trim(preg_replace('/[ \t]+/', ' ', preg_replace('/\R{3,}/', "\n\n", $text)));
        abort_if(mb_strlen($text) < 30, 422, 'No readable CV text found. Scanned PDFs require OCR before upload.');

        return mb_substr($text, 0, 100000);
    }
}
