<?php

namespace App\Http\Middleware;

use App\Support\OneployHosts;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * When Oneploy hosts are configured, send / to the right portal home and
 * bounce paths that belong on another subdomain.
 */
class ResolveOneployHost
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! OneployHosts::splitEnabled()) {
            return $next($request);
        }

        $path = OneployHosts::normalizePath('/'.$request->path());
        $host = strtolower($request->getHost());

        $root = OneployHosts::rootRedirectPath($request);
        if ($root !== null) {
            return redirect()->away(OneployHosts::absoluteUrl($host, $root));
        }

        if (OneployHosts::isExemptPath($path)) {
            return $next($request);
        }

        if (OneployHosts::isSharedAuthPath($path)) {
            if ($host === OneployHosts::marketing()) {
                return redirect()->away(OneployHosts::absoluteUrl(OneployHosts::client(), $request->getRequestUri()));
            }

            return $next($request);
        }

        $correct = OneployHosts::hostForPath($path);
        if ($correct !== '' && $host !== $correct) {
            return redirect()->away(OneployHosts::absoluteUrl($correct, $request->getRequestUri()));
        }

        return $next($request);
    }
}
