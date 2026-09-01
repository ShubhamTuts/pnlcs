<?php

namespace App\Services\Webkahost;

use App\Models\AiApiKey;
use App\Models\AiByokCredential;
use App\Models\Client;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * OpenAI-compatible router that bills Webkahost AI credits.
 *
 * When an upstream URL is configured the request is forwarded and usage is
 * taken from the upstream response. When it is not, a local completion is
 * returned so the portal, tests and the agent still run without a vendor key.
 */
class AiGatewayService
{
    public function __construct(private AiCreditService $credits) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{status: int, body: array<string, mixed>}
     */
    public function chatCompletions(Client $client, array $payload, ?AiApiKey $key = null, string $source = 'gateway'): array
    {
        $model = (string) ($payload['model'] ?? 'gpt-4o-mini');
        if (! isset(AiCreditService::catalogue()[$model])) {
            $model = 'gpt-4o-mini';
        }

        $messages = $payload['messages'] ?? [];
        if (! is_array($messages) || $messages === []) {
            return ['status' => 400, 'body' => ['error' => ['message' => 'messages is required', 'type' => 'invalid_request_error']]];
        }

        $inputTokens = $this->estimateTokens($messages);
        $byok = AiByokCredential::activeFor($client);
        $reserve = $this->credits->costFor($model, $inputTokens, 256);
        if (! $byok && $this->credits->balance($client) < $reserve) {
            return ['status' => 402, 'body' => ['error' => ['message' => 'Insufficient AI credits. Buy a pack or add a BYOK key in the Webkahost portal.', 'type' => 'insufficient_credits']]];
        }

        $started = microtime(true);
        $upstream = $byok ? $byok->upstream() : $this->upstream();

        if ($upstream['url'] !== '') {
            $response = $this->forward($upstream, $model, $payload);
            if ($response === null) {
                return ['status' => 502, 'body' => ['error' => ['message' => 'Upstream AI provider failed', 'type' => 'upstream_error']]];
            }
            $body = $response;
        } else {
            $body = $this->localCompletion($model, $messages);
        }

        $usage = $body['usage'] ?? [];
        $in = (int) ($usage['prompt_tokens'] ?? $inputTokens);
        $out = (int) ($usage['completion_tokens'] ?? $this->estimateTokens([['content' => $body['choices'][0]['message']['content'] ?? '']]));

        if ($byok) {
            $event = $this->credits->recordByok($client, $model, $in, $out, [
                'ai_api_key_id' => $key?->id,
                'source' => 'byok',
                'provider' => $byok->provider,
                'latency_ms' => (int) round((microtime(true) - $started) * 1000),
                'request_id' => $body['id'] ?? Str::uuid()->toString(),
            ]);
            $charged = 0.0;
        } else {
            $event = $this->credits->charge($client, $model, $in, $out, [
                'ai_api_key_id' => $key?->id,
                'source' => $source,
                'latency_ms' => (int) round((microtime(true) - $started) * 1000),
                'request_id' => $body['id'] ?? Str::uuid()->toString(),
            ]);

            if ($event === null) {
                return ['status' => 402, 'body' => ['error' => ['message' => 'Insufficient AI credits', 'type' => 'insufficient_credits']]];
            }
            $charged = (float) $event->credits_charged;
        }

        $body['usage'] = [
            'prompt_tokens' => $in,
            'completion_tokens' => $out,
            'total_tokens' => $in + $out,
            'webkahost_credits' => $charged,
            'webkahost_byok' => (bool) $byok,
        ];

        if ($key) {
            $key->forceFill(['last_used_at' => now()])->save();
        }

        return ['status' => 200, 'body' => $body];
    }

    /**
     * @return list<array{id: string, object: string, owned_by: string}>
     */
    public function models(): array
    {
        $out = [];
        foreach (array_keys(AiCreditService::catalogue()) as $id) {
            $out[] = ['id' => $id, 'object' => 'model', 'owned_by' => 'webkahost'];
        }

        return $out;
    }

    /**
     * @return array{url: string, key: string}
     */
    public function upstream(): array
    {
        return [
            'url' => rtrim((string) (Setting::get('webkahost_ai_upstream_url') ?: env('WEBKAHOST_AI_UPSTREAM_URL', '')), '/'),
            'key' => (string) (Setting::get('webkahost_ai_upstream_key') ?: env('WEBKAHOST_AI_UPSTREAM_KEY', '')),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     */
    private function estimateTokens(array $messages): int
    {
        $chars = 0;
        foreach ($messages as $message) {
            $chars += strlen((string) ($message['content'] ?? ''));
        }

        return max(1, (int) ceil($chars / 4));
    }

    /**
     * @param  array{url: string, key: string}  $upstream
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function forward(array $upstream, string $model, array $payload): ?array
    {
        try {
            $response = Http::withToken($upstream['key'])
                ->acceptJson()
                ->timeout(60)
                ->post($upstream['url'].'/chat/completions', array_merge($payload, ['model' => $model]));

            if (! $response->successful()) {
                return null;
            }

            $json = $response->json();

            return is_array($json) ? $json : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @return array<string, mixed>
     */
    private function localCompletion(string $model, array $messages): array
    {
        $last = '';
        foreach (array_reverse($messages) as $message) {
            if (($message['role'] ?? '') === 'user') {
                $last = (string) ($message['content'] ?? '');
                break;
            }
        }

        $text = $last === ''
            ? 'Webkahost Agent is ready. Tell me what to deploy.'
            : "I received: {$last}\n\nConnect an upstream model in Settings to generate real completions. The Webkahost Agent can still run deploy tools from this message.";

        $id = 'chatcmpl-'.Str::lower(Str::random(24));

        return [
            'id' => $id,
            'object' => 'chat.completion',
            'created' => time(),
            'model' => $model,
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => $text],
                'finish_reason' => 'stop',
            ]],
            'usage' => [
                'prompt_tokens' => $this->estimateTokens($messages),
                'completion_tokens' => $this->estimateTokens([['content' => $text]]),
                'total_tokens' => 0,
            ],
        ];
    }
}
