<?php

namespace App\Services\Ats\Parsing;

/**
 * One structure signal (§4.1, §5.2 `Detection`). `detected` is null when the signal was not inspected
 * (pasted text, or not applicable to the format). `extra` carries signal-specific values such as the
 * image count and largest area; `samples` are up to 3 short evidence strings.
 */
final readonly class Detection
{
    /**
     * @param  array<string, mixed>  $extra
     * @param  list<string>  $samples
     */
    public function __construct(
        public ?bool $detected,
        public ?Confidence $confidence,
        public ?string $note = null,
        public array $extra = [],
        public array $samples = [],
    ) {}

    public static function notInspected(?string $note = null, array $extra = []): self
    {
        return new self(null, null, $note, $extra);
    }

    public static function absent(Confidence $confidence = Confidence::High, array $extra = [], ?string $note = null): self
    {
        return new self(false, $confidence, $note, $extra);
    }

    /** @param  list<string>  $samples */
    public static function found(Confidence $confidence, string $note, array $samples = [], array $extra = []): self
    {
        return new self(true, $confidence, $note, $extra, array_slice(array_values(array_map(
            fn (string $s) => mb_substr(trim($s), 0, 200),
            $samples,
        )), 0, 3));
    }
}
