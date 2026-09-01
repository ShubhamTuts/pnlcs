<?php

namespace App\Services\Webkahost;

use App\Models\AiCreditPack;
use App\Models\AiLedgerEntry;
use App\Models\AiUsageEvent;
use App\Models\AiWallet;
use App\Models\Client;
use Illuminate\Support\Facades\DB;

class AiCreditService
{
    /**
     * Credits charged per 1,000 tokens. Tuned so a $10 Starter pack is a
     * meaningful volume of gpt-4o-mini and a cautious volume of larger models.
     *
     * @return array<string, array{provider: string, input: float, output: float}>
     */
    public static function catalogue(): array
    {
        return [
            'webkahost-agent' => ['provider' => 'webkahost', 'input' => 0.20, 'output' => 0.80],
            'gpt-4o-mini' => ['provider' => 'openai', 'input' => 0.15, 'output' => 0.60],
            'gpt-4o' => ['provider' => 'openai', 'input' => 2.50, 'output' => 10.00],
            'claude-3-5-sonnet' => ['provider' => 'anthropic', 'input' => 3.00, 'output' => 15.00],
            'claude-3-haiku' => ['provider' => 'anthropic', 'input' => 0.25, 'output' => 1.25],
        ];
    }

    public function walletFor(Client $client): AiWallet
    {
        return AiWallet::firstOrCreate(
            ['client_id' => $client->id],
            ['balance' => 0]
        );
    }

    public function balance(Client $client): float
    {
        return (float) $this->walletFor($client)->balance;
    }

    public function credit(Client $client, float $credits, string $type, string $description, array $meta = []): AiWallet
    {
        return DB::transaction(function () use ($client, $credits, $type, $description, $meta) {
            $wallet = AiWallet::where('client_id', $client->id)->lockForUpdate()->first()
                ?? AiWallet::create(['client_id' => $client->id, 'balance' => 0]);

            $wallet->balance = round((float) $wallet->balance + $credits, 4);
            $wallet->save();

            AiLedgerEntry::create([
                'client_id' => $client->id,
                'type' => $type,
                'credits' => $credits,
                'description' => $description,
                'invoice_id' => $meta['invoice_id'] ?? null,
                'usage_event_id' => $meta['usage_event_id'] ?? null,
                'meta' => $meta['extra'] ?? null,
            ]);

            return $wallet;
        });
    }

    /**
     * Charge usage. Returns null when the wallet cannot cover the call.
     */
    public function charge(Client $client, string $model, int $inputTokens, int $outputTokens, array $context = []): ?AiUsageEvent
    {
        $cost = $this->costFor($model, $inputTokens, $outputTokens);

        return DB::transaction(function () use ($client, $model, $inputTokens, $outputTokens, $cost, $context) {
            $wallet = AiWallet::where('client_id', $client->id)->lockForUpdate()->first()
                ?? AiWallet::create(['client_id' => $client->id, 'balance' => 0]);

            if ((float) $wallet->balance + 0.00005 < $cost) {
                return null;
            }

            $wallet->balance = round((float) $wallet->balance - $cost, 4);
            $wallet->save();

            $event = AiUsageEvent::create([
                'client_id' => $client->id,
                'ai_api_key_id' => $context['ai_api_key_id'] ?? null,
                'source' => $context['source'] ?? 'gateway',
                'model' => $model,
                'provider' => self::catalogue()[$model]['provider'] ?? 'webkahost',
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'credits_charged' => $cost,
                'latency_ms' => $context['latency_ms'] ?? null,
                'status' => $context['status'] ?? 'ok',
                'request_id' => $context['request_id'] ?? null,
            ]);

            AiLedgerEntry::create([
                'client_id' => $client->id,
                'type' => 'usage',
                'credits' => -$cost,
                'description' => "AI usage ({$model})",
                'usage_event_id' => $event->id,
            ]);

            return $event;
        });
    }

    public function costFor(string $model, int $inputTokens, int $outputTokens): float
    {
        $rates = self::catalogue()[$model] ?? self::catalogue()['gpt-4o-mini'];
        $cost = ($inputTokens / 1000) * $rates['input'] + ($outputTokens / 1000) * $rates['output'];

        return round(max($cost, 0.0001), 4);
    }

    public function applyPurchase(Client $client, AiCreditPack $pack, int $invoiceId): AiWallet
    {
        return $this->credit(
            $client,
            (float) $pack->credits,
            'purchase',
            "Purchased {$pack->name} ({$pack->credits} credits)",
            ['invoice_id' => $invoiceId]
        );
    }

    public function applyInvoiceCredits(Client $client, int $credits, int $invoiceId, string $label): AiWallet
    {
        return $this->credit(
            $client,
            (float) $credits,
            'purchase',
            $label,
            ['invoice_id' => $invoiceId]
        );
    }
}
