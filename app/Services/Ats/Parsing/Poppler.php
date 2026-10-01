<?php

namespace App\Services\Ats\Parsing;

use Illuminate\Support\Facades\Process;

/**
 * The poppler-utils binaries the PDF parser runs (SPEC-ats.md §4.1, docs/DEPLOYMENT.md).
 * Arguments are always passed as an array (no shell) with a hard timeout.
 */
final class Poppler
{
    public const BINARIES = ['pdftotext', 'pdfinfo', 'pdfimages'];

    public function binary(string $name): string
    {
        return (string) config("ats.poppler.{$name}", $name);
    }

    /**
     * What is wrong with the installation, as human-readable lines; empty when everything works.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $problems = [];
        foreach (self::BINARIES as $name) {
            $result = $this->run([$this->binary($name), '-h']);
            // Poppler prints usage to stderr and exits 0 (or 99 on old versions) for -h.
            $usage = $result === null ? '' : $result['output'].$result['error'];
            if (! str_contains($usage, $name === 'pdfimages' ? '-list' : ($name === 'pdftotext' ? '-bbox-layout' : 'Usage'))) {
                $problems[] = "{$name} ({$this->binary($name)}) is missing or too old. Install poppler-utils: apt-get install poppler-utils (Debian/Ubuntu) or brew install poppler (macOS).";
            }
        }

        return $problems;
    }

    /**
     * @param  list<string>  $command
     * @return array{exit: int, output: string, error: string}|null null when the binary cannot start or times out
     */
    public function run(array $command): ?array
    {
        try {
            $result = Process::timeout((int) config('ats.poppler.timeout', 10))->run($command);
        } catch (\Throwable) {
            return null;
        }

        return ['exit' => (int) $result->exitCode(), 'output' => $result->output(), 'error' => $result->errorOutput()];
    }
}
