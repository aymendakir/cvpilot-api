<?php

namespace App\Http\Controllers;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Serves the legacy static console page at "/". */
class ConsoleController
{
    public function __invoke(): BinaryFileResponse
    {
        return response()->file(public_path('console.html'));
    }
}
