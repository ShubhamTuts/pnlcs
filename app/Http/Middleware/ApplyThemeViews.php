<?php

namespace App\Http\Middleware;

use App\Services\ThemeManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApplyThemeViews
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            app(ThemeManager::class)->applyViewLocations();
        } catch (\Throwable $e) {
            // Installer / migrate: no settings table yet.
        }

        return $next($request);
    }
}
