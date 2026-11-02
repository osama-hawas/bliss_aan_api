<?php

namespace App\Http\Middleware;

use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetApiLanguage
{
    /**
     * Resolve the locale from Accept-Language (primary tag, so `ar-SA` → `ar`).
     * Unsupported or missing values fall back to the app locale (Arabic).
     */
    public function handle(Request $request, Closure $next): Response
    {
        $supported = config('app.supported_locales');
        $default = config('app.locale');

        $locale = $request->hasHeader('Accept-Language')
            ? $this->resolve($request, $supported, $default)
            : $default;

        app()->setLocale($locale);
        Carbon::setLocale($locale);

        $response = $next($request);
        $response->headers->set('Content-Language', $locale);

        return $response;
    }

    private function resolve(Request $request, array $supported, string $default): string
    {
        foreach ($request->getLanguages() as $language) {
            $primary = strtolower(strtok(str_replace('-', '_', $language), '_'));

            if (in_array($primary, $supported, true)) {
                return $primary;
            }
        }

        return $default;
    }
}
