<?php

namespace App\Services\Ats\Parsing;

/**
 * Entry point of `ats-parsing`: picks the parser from the file's real type (§5.1). Pasted text goes
 * through parseText().
 */
final class DocumentReader
{
    public function __construct(
        private readonly DocumentTypeDetector $types = new DocumentTypeDetector,
        private readonly TextParser $text = new TextParser,
        private readonly DocxParser $docx = new DocxParser,
        private readonly PdfParser $pdf = new PdfParser,
    ) {}

    /** @throws UnreadableDocument */
    public function parseFile(string $path, string $clientName = ''): ParsedDocument
    {
        return match ($this->types->detect($path, $clientName)) {
            'pdf' => $this->pdf->parse($path),
            'docx' => $this->docx->parse($path),
            'text' => $this->text->parse($path),
            default => throw new UnreadableDocument(UnreadableDocument::UNSUPPORTED_TYPE),
        };
    }

    public function parseText(string $text): ParsedDocument
    {
        return $this->text->parseText($text);
    }
}
