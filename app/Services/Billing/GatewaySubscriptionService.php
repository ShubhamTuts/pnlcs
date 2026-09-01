<?php

namespace App\Services\Billing;

use App\Models\AiCreditPack;
use App\Models\Client;
use App\Models\GatewayPlan;
use App\Models\GatewaySubscription;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Services\InvoiceService;
use App\Services\Module\ModuleRegistry;
use Illuminate\Support\Facades\Log;
use Modules\Gateways\Razorpay\RazorpayModule;

/**
 * Native gateway subscriptions (Razorpay Plans + Subscriptions).
 *
 * One-time invoices still go through Orders. Recurring hosting and optional
 * AI credit packs create a Razorpay Plan, a Subscription, and Checkout with
 * subscription_id. Later subscription.charged webhooks raise or pay the
 * next PNLCS invoice so the nightly invoice cron does not double-bill.
 */
class GatewaySubscriptionService
{
    public function razorpaySubscriptionsEnabled(): bool
    {
        $module = app(ModuleRegistry::class)->getGatewayModule('razorpay');

        return $module instanceof RazorpayModule && $module->subscriptionsEnabled();
    }

    public function invoiceWantsRazorpaySubscription(Invoice $invoice): bool
    {
        if (! $this->razorpaySubscriptionsEnabled()) {
            return false;
        }

        $invoice->loadMissing('items');

        if ($this->aiPackId($invoice) !== null) {
            $meta = (string) ($invoice->notes ?? '');

            return str_contains($meta, 'razorpay_subscribe=1');
        }

        return $this->recurringService($invoice) !== null;
    }

    public function startRazorpay(Invoice $invoice): array
    {
        $module = app(ModuleRegistry::class)->getGatewayModule('razorpay');
        if (! $module instanceof RazorpayModule) {
            return ['success' => false, 'message' => 'Razorpay is not registered.'];
        }

        $existing = GatewaySubscription::where('gateway', 'razorpay')
            ->where('invoice_id', $invoice->id)
            ->whereIn('status', GatewaySubscription::LIVE)
            ->first();
        if ($existing) {
            return [
                'success' => true,
                'subscription_id' => $existing->remote_id,
                'key_id' => $module->publicKeyId(),
            ];
        }

        $period = $this->periodForInvoice($invoice);
        if ($period === null) {
            return ['success' => false, 'message' => 'This invoice is not a recurring subscription.'];
        }

        [$unit, $interval] = $period;
        $amount = $invoice->amountDue();
        $currency = strtoupper(shop_currency_code());
        $service = $this->recurringService($invoice);
        $packId = $this->aiPackId($invoice);
        $name = $this->planName($invoice, $service, $packId);

        $plan = $module->ensurePlan($name, $unit, $interval, $amount, $currency);
        if (! ($plan['success'] ?? false)) {
            return $plan;
        }

        $cycles = $module->subscriptionCycleCount();
        $notes = [
            'invoice_id' => (string) $invoice->id,
            'client_id' => (string) $invoice->client_id,
        ];
        if ($service) {
            $notes['service_id'] = (string) $service->id;
        }
        if ($packId) {
            $notes['pack_id'] = (string) $packId;
        }

        $created = $module->createSubscription($plan['plan_id'], $cycles, $notes);
        if (! ($created['success'] ?? false)) {
            return $created;
        }

        GatewayPlan::updateOrCreate(
            ['gateway' => 'razorpay', 'remote_id' => $plan['plan_id']],
            [
                'name' => $name,
                'period' => $unit,
                'interval' => $interval,
                'amount_subunit' => (int) round($amount * 100),
                'currency' => $currency,
            ]
        );

        GatewaySubscription::create([
            'client_id' => $invoice->client_id,
            'invoice_id' => $invoice->id,
            'service_id' => $service?->id,
            'ai_credit_pack_id' => $packId,
            'gateway' => 'razorpay',
            'remote_plan_id' => $plan['plan_id'],
            'remote_id' => $created['subscription_id'],
            'status' => $created['status'] ?? 'created',
            'period' => $unit,
            'interval' => $interval,
            'amount' => $amount,
            'currency' => $currency,
            'total_count' => $cycles,
            'paid_count' => 0,
            'meta' => ['notes' => $notes],
        ]);

        return [
            'success' => true,
            'subscription_id' => $created['subscription_id'],
            'key_id' => $module->publicKeyId(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function handleRazorpayCharged(array $payload): array
    {
        $module = app(ModuleRegistry::class)->getGatewayModule('razorpay');
        if (! $module instanceof RazorpayModule) {
            return ['success' => false, 'message' => 'Razorpay is not registered.'];
        }

        $subEntity = $payload['payload']['subscription']['entity'] ?? [];
        $payEntity = $payload['payload']['payment']['entity'] ?? [];
        $remoteId = (string) ($subEntity['id'] ?? '');
        $paymentId = (string) ($payEntity['id'] ?? '');

        if ($remoteId === '' || $paymentId === '') {
            return ['success' => false, 'message' => 'subscription.charged missing ids.'];
        }

        $verified = $module->fetchCapturedPayment($paymentId);
        if (! ($verified['success'] ?? false)) {
            return $verified;
        }

        if (\App\Models\Transaction::where('gateway', 'razorpay')->where('transaction_id', $paymentId)->exists()) {
            return ['success' => true, 'message' => 'Charge already recorded.', 'transaction_id' => $paymentId];
        }

        $row = GatewaySubscription::where('gateway', 'razorpay')->where('remote_id', $remoteId)->first();
        $notes = $subEntity['notes'] ?? [];
        if (! $row) {
            $invoiceId = (int) ($notes['invoice_id'] ?? 0);
            $row = $invoiceId > 0
                ? GatewaySubscription::where('gateway', 'razorpay')->where('invoice_id', $invoiceId)->first()
                : null;
        }

        $amount = (float) ($verified['amount'] ?? 0);
        $invoice = $this->invoiceForCharge($row, $notes, $amount);
        if (! $invoice) {
            return ['success' => false, 'message' => 'No invoice for this subscription charge.'];
        }

        if ($row) {
            $row->forceFill([
                'status' => (string) ($subEntity['status'] ?? $row->status),
                'paid_count' => (int) ($subEntity['paid_count'] ?? $row->paid_count + 1),
                'invoice_id' => $row->invoice_id ?: $invoice->id,
            ])->save();
        }

        return [
            'success' => true,
            'transaction_id' => $paymentId,
            'invoice_id' => $invoice->id,
            'amount' => $amount,
            'gateway' => 'razorpay',
        ];
    }

    public function markStatus(string $remoteId, string $status): void
    {
        GatewaySubscription::where('gateway', 'razorpay')->where('remote_id', $remoteId)
            ->update(['status' => $status]);
    }

    public function syncServiceStatus(Service $service): void
    {
        $module = app(ModuleRegistry::class)->getGatewayModule('razorpay');
        if (! $module instanceof RazorpayModule) {
            return;
        }

        $rows = GatewaySubscription::where('service_id', $service->id)
            ->where('gateway', 'razorpay')
            ->whereIn('status', GatewaySubscription::LIVE)
            ->get();

        $status = strtolower((string) $service->status);
        foreach ($rows as $row) {
            if (in_array($status, ['terminated', 'cancelled', 'fraud'], true)) {
                $module->cancelSubscription($row->remote_id);
                $row->update(['status' => 'cancelled']);
            } elseif ($status === 'suspended') {
                $module->pauseSubscription($row->remote_id);
                $row->update(['status' => 'paused']);
            } elseif ($status === 'active' && $row->status === 'paused') {
                $module->resumeSubscription($row->remote_id);
                $row->update(['status' => 'active']);
            }
        }
    }

    /**
     * @return array{0: string, 1: int}|null
     */
    public function periodForInvoice(Invoice $invoice): ?array
    {
        if ($this->aiPackId($invoice) !== null && str_contains((string) $invoice->notes, 'razorpay_subscribe=1')) {
            return ['monthly', 1];
        }

        $service = $this->recurringService($invoice);
        if (! $service) {
            return null;
        }

        return $this->periodFromCycle($service->billing_cycle);
    }

    /**
     * @return array{0: string, 1: int}|null
     */
    public function periodFromCycle(?string $cycle): ?array
    {
        $key = strtolower(str_replace([' ', '-', '_'], '', (string) $cycle));

        return match ($key) {
            'monthly' => ['monthly', 1],
            'quarterly' => ['monthly', 3],
            'semiannually' => ['monthly', 6],
            'annually' => ['yearly', 1],
            'biennially' => ['yearly', 2],
            'triennially' => ['yearly', 3],
            'weekly' => ['weekly', 1],
            default => null,
        };
    }

    public function recurringService(Invoice $invoice): ?Service
    {
        $item = $invoice->items->first(fn (InvoiceItem $row) => in_array($row->type, ['Hosting', 'Addon', 'Domain'], true) && (int) $row->rel_id > 0);
        if (! $item || $item->type !== 'Hosting') {
            return null;
        }

        $service = Service::with('product')->find((int) $item->rel_id);
        if (! $service) {
            return null;
        }

        $payType = strtolower((string) ($service->product?->pay_type ?? 'recurring'));
        if (in_array($payType, ['onetime', 'free', 'one_time'], true)) {
            return null;
        }

        return $this->periodFromCycle($service->billing_cycle) ? $service : null;
    }

    public function aiPackId(Invoice $invoice): ?int
    {
        $item = $invoice->items->first(fn (InvoiceItem $row) => $row->type === 'AiCredits');

        return $item ? (int) $item->rel_id : null;
    }

    /**
     * @param  array<string, mixed>  $notes
     */
    private function invoiceForCharge(?GatewaySubscription $row, array $notes, float $amount): ?Invoice
    {
        $seedId = (int) ($row?->invoice_id ?: ($notes['invoice_id'] ?? 0));
        if ($seedId > 0) {
            $seed = Invoice::with('items')->find($seedId);
            if ($seed && in_array($seed->status, ['unpaid', 'overdue', 'partially_paid'], true) && $seed->amountDue() > 0) {
                return $seed;
            }
        }

        if ($row?->service_id) {
            $open = Invoice::query()
                ->where('client_id', $row->client_id)
                ->outstanding()
                ->whereHas('items', fn ($q) => $q->where('type', 'Hosting')->where('rel_id', $row->service_id))
                ->first();
            if ($open) {
                return $open;
            }

            $service = Service::with('client')->find($row->service_id);
            if ($service?->client) {
                return $this->issueServiceInvoice($service, $amount);
            }
        }

        if ($row?->ai_credit_pack_id) {
            $pack = AiCreditPack::find($row->ai_credit_pack_id);
            $client = Client::find($row->client_id);
            if ($pack && $client) {
                return $this->issueCreditInvoice($client, $pack, $row->id);
            }
        }

        return null;
    }

    private function issueServiceInvoice(Service $service, float $amount): Invoice
    {
        $charge = $amount > 0 ? $amount : (float) $service->amount;
        $client = $service->client;
        $invoice = Invoice::create([
            'client_id' => $client->id,
            ...Invoice::buyerSnapshotFrom($client),
            'invoice_num' => app(InvoiceService::class)->generateInvoiceNumber(),
            'date' => today(),
            'due_date' => $service->next_due_date ?? today(),
            'subtotal' => $charge,
            'credit' => 0,
            'tax' => 0,
            'tax2' => 0,
            'total' => $charge,
            'tax_rate' => 0,
            'tax_rate2' => 0,
            'status' => 'unpaid',
            'payment_method' => 'razorpay',
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'client_id' => $client->id,
            'type' => 'Hosting',
            'rel_id' => $service->id,
            'description' => ($service->product?->name ?? 'Service').' renewal',
            'amount' => $charge,
            'taxed' => false,
        ]);

        return $invoice->fresh('items');
    }

    private function issueCreditInvoice(Client $client, AiCreditPack $pack, int $subscriptionId): Invoice
    {
        $invoice = Invoice::create([
            'client_id' => $client->id,
            ...Invoice::buyerSnapshotFrom($client),
            'invoice_num' => app(InvoiceService::class)->generateInvoiceNumber(),
            'date' => today(),
            'due_date' => today(),
            'subtotal' => $pack->price,
            'credit' => 0,
            'tax' => 0,
            'tax2' => 0,
            'total' => $pack->price,
            'tax_rate' => 0,
            'tax_rate2' => 0,
            'status' => 'unpaid',
            'payment_method' => 'razorpay',
            'notes' => 'razorpay_subscribe=1 subscription:'.$subscriptionId,
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'client_id' => $client->id,
            'type' => 'AiCredits',
            'rel_id' => $pack->id,
            'description' => "Webkahost AI Credits — {$pack->name} ({$pack->credits} credits)",
            'amount' => $pack->price,
            'taxed' => false,
        ]);

        return $invoice->fresh('items');
    }

    private function planName(Invoice $invoice, ?Service $service, ?int $packId): string
    {
        if ($service) {
            return 'PNLCS '.$service->product?->name.' '.$service->billing_cycle;
        }
        if ($packId) {
            $pack = AiCreditPack::find($packId);

            return 'PNLCS AI '.$pack?->name;
        }

        return 'PNLCS invoice '.$invoice->id;
    }
}
