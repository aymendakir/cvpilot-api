<?php

return [
    // Date after which the deprecated pre-/api/v1 routes may be removed (sent as the Sunset header).
    // Leave unset until the frontend migration is scheduled.
    'legacy_sunset' => env('API_LEGACY_SUNSET'),
];
