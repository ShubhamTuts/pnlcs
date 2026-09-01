<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * oneploy.dev / client.oneploy.dev / billing.oneploy.dev.
 *
 * Empty config = one host (tests and local). All three set = split portals.
 */
class OneployHosts
{
    public static function marketing(): string
    {
        return strtolower(trim((string) config('oneploy.marketing_host', '')));
    }

    public static function client(): string
    {
        return strtolower(trim((string) config('oneploy.client_host', '')));
    }

    public static function billing(): string
    {
        return strtolower(trim((string) config('oneploy.billing_host', '')));
    }

    public static function splitEnabled(): bool
    {
        return self::marketing() !== '' && self::client() !== '' && self::billing() !== '';
    }

    public static function portal(string $name): string
    {
        return match ($name) {
            'client' => self::client(),
            'billing' => self::billing(),
            default => self::marketing(),
        };
    }

    /**
     * Absolute URL on a named portal. Falls back to url() when hosts are unset.
     */
    public static function url(string $portal, string $path = '/'): string
    {
        $path = self::normalizePath($path);
        $host = self::portal($portal);
        if ($host === '' || ! self::splitEnabled()) {
            return url($path);
        }

        return self::absoluteUrl($host, $path);
    }

    public static function absoluteUrl(string $host, string $pathOrUri): string
    {
        $scheme = request()?->getScheme() ?: 'https';
        if (! str_starts_with($pathOrUri, '/')) {
            $pathOrUri = '/'.$pathOrUri;
        }

        return $scheme.'://'.$host.$pathOrUri;
    }

    /**
     * Which host should serve this path, or empty when it should stay put.
     */
    public static function hostForPath(string $path): string
    {
        $path = self::normalizePath($path);

        if (self::isSharedAuthPath($path) || self::isExemptPath($path)) {
            return '';
        }

        if ($path === '/') {
            return self::marketing();
        }

        if (str_starts_with($path, '/admin')) {
            return self::billing();
        }

        if (self::isBillingPath($path)) {
            return self::billing();
        }

        if (str_starts_with($path, '/client')) {
            return self::client();
        }

        return self::marketing();
    }

    public static function isExemptPath(string $path): bool
    {
        $path = self::normalizePath($path);

        return $path === '/up'
            || str_starts_with($path, '/install')
            || str_starts_with($path, '/gateway')
            || str_starts_with($path, '/api');
    }

    public static function isSharedAuthPath(string $path): bool
    {
        $path = self::normalizePath($path);

        foreach ([
            '/client/login',
            '/client/register',
            '/client/forgot-password',
            '/client/reset-password',
            '/client/2fa',
            '/client/logout',
        ] as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }

    public static function isBillingPath(string $path): bool
    {
        $path = self::normalizePath($path);

        if (str_starts_with($path, '/client/ai/agent')) {
            return false;
        }

        foreach ([
            '/client/invoices',
            '/client/quotes',
            '/client/payment-methods',
            '/client/ai',
            '/client/account/payment-methods',
        ] as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }

    public static function rootRedirectPath(Request $request): ?string
    {
        if ($request->path() !== '/') {
            return null;
        }

        $host = strtolower($request->getHost());
        if ($host === self::client()) {
            return '/client/home';
        }
        if ($host === self::billing()) {
            return '/client/invoices';
        }

        return null;
    }

    public static function normalizePath(string $path): string
    {
        if ($path === '' || $path === '/') {
            return '/';
        }

        $path = '/'.ltrim($path, '/');
        if (strlen($path) > 1) {
            $path = rtrim($path, '/');
        }

        return $path;
    }
}
