<?php

$mib = 1024 * 1024;

return [

    /*
    | Default display timezone for new users. Stored timestamps are always UTC;
    | each user can pick their own display timezone in Settings → Preferences.
    */
    /*
    | Proxy IPs (comma-separated) or '*' whose forwarded headers are trusted.
    | Empty trusts none. Applied in AppServiceProvider (env() is not available
    | while bootstrap/app.php is evaluated, but config is by request time).
    */
    'trusted_proxies' => env('TRUSTED_PROXIES', ''),

    'display_timezone' => env('STASH_DISPLAY_TIMEZONE', 'UTC'),

    /*
    | Private disk holding originals, generated variants, exports and imports.
    | It must not be web-served. Swap to an S3-compatible disk later by
    | changing STASH_STORAGE_DISK and defining that disk in filesystems.php.
    */
    'disk' => env('STASH_STORAGE_DISK', 'stash'),

    'uploads' => [
        'max_bytes' => (int) env('STASH_UPLOAD_MAX_BYTES', 10 * $mib),
        'max_pixels' => (int) env('STASH_UPLOAD_MAX_PIXELS', 40_000_000),
        'max_dimension' => (int) env('STASH_UPLOAD_MAX_DIMENSION', 20_000),
        'allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp'],
    ],

    'videos' => [
        'max_bytes' => (int) env('STASH_VIDEO_MAX_BYTES', 200 * 1024 * 1024),
        'allowed_mimes' => ['video/mp4', 'video/webm', 'video/quicktime'],
        'poster_max_bytes' => 5 * 1024 * 1024,
    ],

    'images' => [
        // gd (default, verified locally) or imagick (requires the PHP extension).
        'driver' => env('STASH_IMAGE_DRIVER', 'gd'),
        'thumbnail_width' => 640,
        'thumbnail_max_height' => 1280,
        'preview_max' => 1600,
        'quality' => 82,
        'memory_limit' => env('STASH_IMAGE_MEMORY_LIMIT', '512M'),
    ],

    'fetch' => [
        'connect_timeout' => (float) env('STASH_FETCH_CONNECT_TIMEOUT', 5),
        'timeout' => (float) env('STASH_FETCH_TIMEOUT', 10),
        'max_html_bytes' => (int) env('STASH_FETCH_MAX_HTML_BYTES', 2 * $mib),
        'max_image_bytes' => (int) env('STASH_FETCH_MAX_IMAGE_BYTES', 5 * $mib),
        'max_redirects' => (int) env('STASH_FETCH_MAX_REDIRECTS', 5),
        'allowed_ports' => array_map('intval', explode(',', (string) env('STASH_FETCH_ALLOWED_PORTS', '80,443,8080,8443'))),
        'user_agent' => env('STASH_FETCH_USER_AGENT', 'StashMetadataBot/1.0 (+private personal library)'),
    ],

    'pagination' => [
        'default' => 24,
        'max' => 100,
        'allowed' => [24, 48, 96],
    ],

    'idempotency' => [
        'ttl_hours' => (int) env('STASH_IDEMPOTENCY_TTL_HOURS', 24),
    ],

    'exports' => [
        'ttl_hours' => (int) env('STASH_EXPORT_TTL_HOURS', 72),
    ],

    'imports' => [
        'max_upload_bytes' => (int) env('STASH_IMPORT_MAX_BYTES', 512 * $mib),
        'max_entries' => (int) env('STASH_IMPORT_MAX_ENTRIES', 20_000),
        'max_expanded_bytes' => (int) env('STASH_IMPORT_MAX_EXPANDED_BYTES', 4096 * $mib),
        'max_compression_ratio' => (int) env('STASH_IMPORT_MAX_RATIO', 200),
        'max_json_bytes' => (int) env('STASH_IMPORT_MAX_JSON_BYTES', 64 * $mib),
        'ttl_hours' => 72,
    ],

    'ai' => [
        'enabled' => (bool) env('AI_ENABLED', false),
        'provider' => env('AI_PROVIDER', 'deepseek'),
        'monthly_token_limit' => (int) env('AI_MONTHLY_TOKEN_LIMIT', 200_000),
        'max_input_chars' => (int) env('AI_MAX_INPUT_CHARS', 24_000),
        'max_output_tokens' => (int) env('AI_MAX_OUTPUT_TOKENS', 800),
        'timeout' => (int) env('AI_TIMEOUT', 60),
        'max_attempts' => 3,
        'prompt_version' => 'sum-v1',
    ],

    'tokens' => [
        'abilities' => [
            'resources:read' => 'Read resources, files, search',
            'resources:write' => 'Create and update resources, bulk actions',
            'resources:delete' => 'Permanently delete resources (privileged)',
            'projects:read' => 'Read projects',
            'projects:write' => 'Create, rename, delete projects',
            'tags:read' => 'Read tags',
            'tags:write' => 'Create, rename, delete tags',
            'ai:run' => 'Request AI summaries (spends token budget)',
            'data:export' => 'Create and download exports',
            'data:import' => 'Upload and apply imports',
        ],
    ],
];
