<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class SetApiLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        // Only negotiate a locale for API/JSON traffic so web (Blade) rendering
        // keeps the application default locale. Running globally lets even
        // unmatched-route 404s and other pre-routing exceptions render in the
        // caller's requested locale.
        if ($request->is('api/*') || $request->expectsJson()) {
            [$locale, $fallback] = $this->resolveLocale($request);

            app()->setLocale($locale);
            app()->setFallbackLocale($fallback);
        }

        return $next($request);
    }

    /**
     * @return array<int, string>
     */
    private function supportedLocales(): array
    {
        $locales = config('localization.supported_locales', ['en']);

        if (! is_array($locales) || $locales === []) {
            return ['en'];
        }

        return array_values(array_filter(
            array_map(
                static fn (mixed $locale): ?string => is_string($locale) && trim($locale) !== '' ? strtolower(trim($locale)) : null,
                $locales
            ),
            static fn (?string $locale): bool => $locale !== null
        ));
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolveLocale(Request $request): array
    {
        $supported = $this->supportedLocales();
        $fallback = $this->canonicalLocale(config('localization.fallback_locale', config('app.fallback_locale', 'en')), $supported)
            ?? ($supported[0] ?? 'en');

        $headerConfig = config('localization.locale_header', 'X-Locale');
        $headerName = is_string($headerConfig) && $headerConfig !== '' ? $headerConfig : 'X-Locale';
        $explicit = $this->canonicalLocale($request->header($headerName), $supported);
        if ($explicit !== null) {
            return [$explicit, $fallback];
        }

        $preferred = $request->getPreferredLanguage($supported);
        if (is_string($preferred) && $preferred !== '') {
            return [$preferred, $fallback];
        }

        $default = $this->canonicalLocale(config('localization.default_locale', config('app.locale', 'en')), $supported);
        if ($default !== null) {
            return [$default, $fallback];
        }

        return [$fallback, $fallback];
    }

    /**
     * @param  array<int, string>  $supported
     */
    private function canonicalLocale(mixed $candidate, array $supported): ?string
    {
        if (! is_string($candidate)) {
            return null;
        }

        $normalized = strtolower(trim(str_replace('_', '-', $candidate)));
        if ($normalized === '') {
            return null;
        }

        if (in_array($normalized, $supported, true)) {
            return $normalized;
        }

        $primary = strtok($normalized, '-');
        if (in_array($primary, $supported, true)) {
            return $primary;
        }

        return null;
    }
}
