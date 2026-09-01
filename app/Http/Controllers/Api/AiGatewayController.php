<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiApiKey;
use App\Models\AiUsageEvent;
use App\Models\Client;
use App\Services\Webkahost\AiGatewayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiGatewayController extends Controller
{
    public function models(Request $request): JsonResponse
    {
        return response()->json([
            'object' => 'list',
            'data' => app(AiGatewayService::class)->models(),
        ]);
    }

    public function chatCompletions(Request $request): JsonResponse
    {
        /** @var Client $client */
        $client = $request->attributes->get('ai_client');
        /** @var AiApiKey|null $key */
        $key = $request->attributes->get('ai_api_key');

        $result = app(AiGatewayService::class)->chatCompletions($client, $request->all(), $key);

        return response()->json($result['body'], $result['status']);
    }

    public function usage(Request $request): JsonResponse
    {
        /** @var Client $client */
        $client = $request->attributes->get('ai_client');

        $events = AiUsageEvent::where('client_id', $client->id)
            ->orderByDesc('id')
            ->limit(100)
            ->get(['id', 'model', 'provider', 'input_tokens', 'output_tokens', 'credits_charged', 'status', 'created_at']);

        return response()->json([
            'object' => 'list',
            'data' => $events,
        ]);
    }
}
