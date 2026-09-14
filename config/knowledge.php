<?php

return [
    // Opt in only after every API instance has the publication-aware reader.
    'public_enabled' => env('KNOWLEDGE_PUBLIC_ENABLED', false),
    // Explicit backend public origin for relative article resources, never Host.
    'media_base_url' => env('KNOWLEDGE_MEDIA_BASE_URL'),
];
