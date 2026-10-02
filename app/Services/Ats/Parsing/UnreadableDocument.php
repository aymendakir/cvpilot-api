<?php

namespace App\Services\Ats\Parsing;

use RuntimeException;

/**
 * The file cannot be analysed at all (S4 maps it to `422 errors.file`). A scanned PDF is *not* this:
 * it parses to a document without extractable text and gets an `unreadable` report (§6 R4).
 */
final class UnreadableDocument extends RuntimeException
{
    public const PASSWORD_PROTECTED = 'password_protected';

    public const CORRUPT = 'corrupt';

    public const UNSUPPORTED_TYPE = 'unsupported_type';

    public const TIMEOUT = 'timeout';

    /** Text extraction only (DocumentExtractor): the file opened but holds fewer than 30 characters of text. */
    public const NO_TEXT = 'no_text';

    public function __construct(public readonly string $reason, string $message = '', ?\Throwable $previous = null)
    {
        parent::__construct($message !== '' ? $message : $reason, 0, $previous);
    }
}
