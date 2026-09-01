<?php

namespace App\Http\Middleware;

use App\Models\AiApiKey;
use Closure;
use Illuminate\Http\Request;

class AuthenticateAiKey
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken()
            ?: (string) $request->header('X-Api-Key', '');

        if ($token === '' && str_starts_with((string) $request->header('Authorization'), 'Bearer ')) {
            $token = substr((string) $request->header('Authorization'), 7);
        }

        if ($token === '' || ! str_starts_with($token, 'wk_live_')) {
            return response()->json([
                'error' => ['message' => 'Missing Webkahost AI key. Send Authorization: Bearer wk_live_…', 'type' => 'invalid_api_key'],
            ], 401);
        }

        $key = AiApiKey::where('key_hash', AiApiKey::hashKey($token))
            ->whereNull('revoked_at')
            ->first();

        if (! $key || ! $key->client) {
            return response()->json([
                'error' => ['message' => 'Invalid Webkahost AI key', 'type' => 'invalid_api_key'],
            ], 401);
        }

        $request->attributes->set('ai_api_key', $key);
        $request->attributes->set('ai_client', $key->client);

        return $next($request);
    }
}
