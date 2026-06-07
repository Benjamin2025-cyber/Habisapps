<?php

declare(strict_types=1);

return [
    'supported_locales' => array_values(array_filter(array_map(
        static fn (string $locale): string => strtolower(trim($locale)),
        preg_split('/\s*,\s*/', (string) env('API_SUPPORTED_LOCALES', 'en,fr')) ?: []
    ))),
    'default_locale' => strtolower((string) env('API_DEFAULT_LOCALE', env('APP_LOCALE', 'en'))),
    'fallback_locale' => strtolower((string) env('API_FALLBACK_LOCALE', env('APP_FALLBACK_LOCALE', 'en'))),
    'locale_header' => (string) env('API_LOCALE_HEADER', 'X-Locale'),
    'meta_enabled' => filter_var(env('API_LOCALE_META_ENABLED', false), FILTER_VALIDATE_BOOL),
];
