<?php

namespace Modules\Gateways\Razorpay;

use App\Contracts\GatewayModuleInterface;
use App\Models\GatewaySettings;
use App\Models\Invoice;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RazorpayModule implements GatewayModuleInterface
{
    protected string $apiUrl = 'https://api.razorpay.com/v1';

    public function getModuleName(): string
    {
        return 'Razorpay';
    }

    public function isTokenised(): bool
    {
        return false;
    }

    public function getConfigFields(): array
    {
        return [
            ['name' => 'key_id', 'label' => 'Razorpay Key ID', 'type' => 'text', 'required' => true],
            ['name' => 'key_secret', 'label' => 'Razorpay Key Secret', 'type' => 'password', 'required' => true],
            ['name' => 'webhook_secret', 'label' => 'Webhook Secret', 'type' => 'password'],
            ['name' => 'test_mode', 'label' => 'Test Mode (rzp_test_ keys)', 'type' => 'yesno', 'default' => '1'],
            ['name' => 'enable_subscriptions', 'label' => 'Enable Razorpay Subscriptions', 'type' => 'yesno', 'default' => '1'],
            ['name' => 'subscription_cycles', 'label' => 'Subscription cycles (total_count)', 'type' => 'text', 'default' => '120'],
        ];
    }

    private function getSetting(string $key): ?string
    {
        return GatewaySettings::where('gateway', 'razorpay')->where('setting', $key)->first()?->value;
    }

    public function publicKeyId(): string
    {
        return (string) ($this->getSetting('key_id') ?? '');
    }

    public function subscriptionsEnabled(): bool
    {
        $flag = $this->getSetting('enable_subscriptions');

        return $flag === null || $flag === '' || $flag === '1';
    }

    public function subscriptionCycleCount(): int
    {
        $n = (int) ($this->getSetting('subscription_cycles') ?: 120);

        return max(1, min($n, 1200));
    }

    /**
     * @return array{success: bool, plan_id?: string, message?: string}
     */
    public function ensurePlan(string $name, string $period, int $interval, float $amount, string $currency): array
    {
        $cached = \App\Models\GatewayPlan::where('gateway', 'razorpay')
            ->where('name', $name)
            ->where('period', $period)
            ->where('interval', $interval)
            ->where('amount_subunit', (int) round($amount * 100))
            ->where('currency', strtoupper($currency))
            ->first();
        if ($cached) {
            return ['success' => true, 'plan_id' => $cached->remote_id];
        }

        $result = $this->api('POST', 'plans', [
            'period' => $period,
            'interval' => $interval,
            'item' => [
                'name' => $name,
                'amount' => (int) round($amount * 100),
                'currency' => strtoupper($currency),
                'description' => $name,
            ],
        ]);

        if (! ($result['success'] ?? false)) {
            return ['success' => false, 'message' => $result['message'] ?? 'Could not create Razorpay plan.'];
        }

        $id = (string) ($result['raw']['id'] ?? '');
        if ($id === '') {
            return ['success' => false, 'message' => 'Razorpay plan response had no id.'];
        }

        return ['success' => true, 'plan_id' => $id];
    }

    /**
     * @param  array<string, string>  $notes
     * @return array{success: bool, subscription_id?: string, status?: string, message?: string}
     */
    public function createSubscription(string $planId, int $totalCount, array $notes): array
    {
        $result = $this->api('POST', 'subscriptions', [
            'plan_id' => $planId,
            'total_count' => $totalCount,
            'quantity' => 1,
            'customer_notify' => true,
            'notes' => $notes,
        ]);

        if (! ($result['success'] ?? false)) {
            return ['success' => false, 'message' => $result['message'] ?? 'Could not create Razorpay subscription.'];
        }

        $id = (string) ($result['raw']['id'] ?? '');
        if ($id === '') {
            return ['success' => false, 'message' => 'Razorpay subscription response had no id.'];
        }

        return [
            'success' => true,
            'subscription_id' => $id,
            'status' => (string) ($result['raw']['status'] ?? 'created'),
        ];
    }

    public function cancelSubscription(string $subscriptionId): array
    {
        return $this->api('POST', "subscriptions/{$subscriptionId}/cancel", ['cancel_at_cycle_end' => 0]);
    }

    public function pauseSubscription(string $subscriptionId): array
    {
        return $this->api('POST', "subscriptions/{$subscriptionId}/pause", ['pause_at' => 'now']);
    }

    public function resumeSubscription(string $subscriptionId): array
    {
        return $this->api('POST', "subscriptions/{$subscriptionId}/resume", ['resume_at' => 'now']);
    }

    /**
     * @return array{success: bool, amount?: float, message?: string, notes?: array}
     */
    public function fetchCapturedPayment(string $paymentId): array
    {
        $result = $this->api('GET', 'payments/'.$paymentId);
        if (! ($result['success'] ?? false)) {
            return ['success' => false, 'message' => $result['message'] ?? 'Payment lookup failed.'];
        }

        $status = (string) ($result['raw']['status'] ?? '');
        if (! in_array($status, ['captured', 'authorized'], true)) {
            return ['success' => false, 'message' => "Payment status is {$status}, not captured."];
        }

        return [
            'success' => true,
            'amount' => ((int) ($result['raw']['amount'] ?? 0)) / 100,
            'notes' => is_array($result['raw']['notes'] ?? null) ? $result['raw']['notes'] : [],
        ];
    }

    /**
     * Checkout signature for a Subscription (payment_id|subscription_id).
     *
     * @return array{success: bool, transaction_id?: string, amount?: float, message?: string}
     */
    public function verifySubscriptionPayment(string $subscriptionId, string $paymentId, string $signature, int $expectedInvoiceId): array
    {
        $keySecret = $this->getSetting('key_secret');
        if (! $keySecret) {
            return ['success' => false, 'message' => 'Razorpay credentials not configured.'];
        }
        if ($subscriptionId === '' || $paymentId === '' || $signature === '') {
            return ['success' => false, 'message' => 'Missing Razorpay subscription fields.'];
        }

        $expected = hash_hmac('sha256', "{$paymentId}|{$subscriptionId}", $keySecret);
        if (! hash_equals($expected, $signature)) {
            Log::warning('Razorpay: subscription signature verification failed', ['sub' => $subscriptionId, 'payment' => $paymentId]);

            return ['success' => false, 'message' => 'Invalid payment signature.'];
        }

        $sub = $this->api('GET', 'subscriptions/'.$subscriptionId);
        if (! ($sub['success'] ?? false)) {
            return ['success' => false, 'message' => 'Razorpay: subscription lookup failed.'];
        }

        $notesInvoice = (int) ($sub['raw']['notes']['invoice_id'] ?? 0);
        if ($notesInvoice !== $expectedInvoiceId) {
            return ['success' => false, 'message' => 'Payment does not match this invoice.'];
        }

        $payment = $this->fetchCapturedPayment($paymentId);
        if (! ($payment['success'] ?? false)) {
            return $payment;
        }

        return [
            'success' => true,
            'transaction_id' => $paymentId,
            'amount' => $payment['amount'],
        ];
    }

    /**
     * @return array{success: bool, message?: string, raw?: array}
     */
    private function api(string $method, string $endpoint, array $body = []): array
    {
        $keyId = $this->getSetting('key_id');
        $keySecret = $this->getSetting('key_secret');
        if (! $keyId || ! $keySecret) {
            return ['success' => false, 'message' => 'Razorpay credentials not configured.', 'raw' => []];
        }

        $url = $this->apiUrl.'/'.ltrim($endpoint, '/');
        try {
            $request = Http::withBasicAuth($keyId, $keySecret)->acceptJson()->timeout(45);
            $response = strtoupper($method) === 'GET'
                ? $request->get($url)
                : $request->post($url, $body);

            if (! $response->successful()) {
                $error = $response->json('error.description', 'HTTP '.$response->status());

                return ['success' => false, 'message' => is_string($error) ? $error : 'Razorpay error', 'raw' => $response->json() ?: []];
            }

            $json = $response->json();

            return ['success' => true, 'raw' => is_array($json) ? $json : []];
        } catch (\Throwable $e) {
            Log::error('Razorpay API error: '.$e->getMessage(), ['endpoint' => $endpoint]);

            return ['success' => false, 'message' => $e->getMessage(), 'raw' => []];
        }
    }

    public function capture(Invoice $invoice, float $amount, array $params = []): array
    {
        $keyId = $this->getSetting('key_id');
        $keySecret = $this->getSetting('key_secret');

        if (!$keyId || !$keySecret) {
            return ['success' => false, 'message' => 'Razorpay credentials not configured.'];
        }

        $currency = strtoupper($params['currency'] ?? shop_currency_code());
        $amountPaise = (int) round($amount * 100);
        $invoiceNum = $invoice->invoice_num ?? $invoice->id;

        $response = Http::withBasicAuth($keyId, $keySecret)
            ->post("{$this->apiUrl}/orders", [
                'amount' => $amountPaise,
                'currency' => $currency,
                'receipt' => "invoice_{$invoiceNum}",
                'notes' => [
                    'invoice_id' => $invoice->id,
                    'invoice_num' => $invoiceNum,
                ],
            ]);

        if (!$response->successful()) {
            $error = $response->json('error.description', 'Unknown Razorpay error');
            Log::error('Razorpay: create order failed', ['invoice' => $invoice->id, 'error' => $error]);
            return ['success' => false, 'message' => "Razorpay error: {$error}"];
        }

        $order = $response->json();

        return [
            'success' => true,
            'order_id' => $order['id'] ?? null,
            'key_id' => $keyId,
            'amount' => $amountPaise,
            'currency' => $currency,
        ];
    }

    public function refund(string $transactionId, float $amount): array
    {
        $keyId = $this->getSetting('key_id');
        $keySecret = $this->getSetting('key_secret');

        if (!$keyId || !$keySecret) {
            return ['success' => false, 'message' => 'Razorpay credentials not configured.'];
        }

        $amountPaise = (int) round($amount * 100);

        $response = Http::withBasicAuth($keyId, $keySecret)
            ->post("{$this->apiUrl}/payments/{$transactionId}/refund", [
                'amount' => $amountPaise,
            ]);

        if (!$response->successful()) {
            $error = $response->json('error.description', 'Unknown Razorpay error');
            return ['success' => false, 'message' => "Razorpay refund error: {$error}"];
        }

        $data = $response->json();
        return [
            'success' => true,
            'refund_id' => $data['id'] ?? null,
            'transaction_id' => $transactionId,
        ];
    }

    public function getPaymentForm(Invoice $invoice): string
    {
        $keyId = htmlspecialchars($this->getSetting('key_id') ?? '', ENT_QUOTES, 'UTF-8');
        $amount = (int) round($invoice->amountDue() * 100);
        $invoiceId = (int) $invoice->id;
        $invoiceNum = $invoice->invoice_num ?? $invoice->id;
        $displayAmount = money_fmt($invoice->amountDue());
        $currency = shop_currency_code();
        $captureUrl = url("/gateway/razorpay/capture/{$invoiceId}");
        $companyName = htmlspecialchars(\App\Models\Setting::get('CompanyName', 'PNLCS'), ENT_QUOTES, 'UTF-8');

        if (!$keyId) {
            return '<div class="alert alert-danger">Razorpay is not configured.</div>';
        }

        $subscribeHint = app(\App\Services\Billing\GatewaySubscriptionService::class)->invoiceWantsRazorpaySubscription($invoice)
            ? '<p class="form-hint">'.e(__('client.invoices.razorpay_subscribe_hint')).'</p>'
            : '';

        return <<<HTML
<div class="my-3">
    {$subscribeHint}
    <button id="rzp-pay-btn" class="btn btn-primary w-100" type="button">Pay {$displayAmount} with Razorpay</button>
    <div id="rzp-message" class="mt-2"></div>
</div>
<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
<script>
(function() {
    var csrfToken = document.querySelector('meta[name=csrf-token]')?.content ?? '';
    document.getElementById('rzp-pay-btn').addEventListener('click', function() {
        fetch("{$captureUrl}", {
            method: "POST",
            headers: {"Content-Type":"application/json","X-CSRF-TOKEN":csrfToken}
        })
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                document.getElementById('rzp-message').innerHTML = '<div class="alert alert-danger">'+(data.message||'Failed')+'</div>';
                return;
            }
            var options = {
                key: data.key_id || "{$keyId}",
                name: "{$companyName}",
                description: "Invoice #{$invoiceNum}",
                handler: function(response) {
                    fetch("{$captureUrl}", {
                        method: "POST",
                        headers: {"Content-Type":"application/json","X-CSRF-TOKEN":csrfToken},
                        body: JSON.stringify({
                            razorpay_payment_id: response.razorpay_payment_id,
                            razorpay_order_id: response.razorpay_order_id || '',
                            razorpay_subscription_id: response.razorpay_subscription_id || '',
                            razorpay_signature: response.razorpay_signature,
                            confirm: true
                        })
                    })
                    .then(r => r.json())
                    .then(res => {
                        if (res.success) window.location.href = res.redirect_url || "/client/invoices/{$invoiceId}?payment=success";
                        else document.getElementById('rzp-message').innerHTML = '<div class="alert alert-danger">'+(res.message||'Failed')+'</div>';
                    });
                },
                theme: { color: "#405189" }
            };
            if (data.subscription_id) {
                options.subscription_id = data.subscription_id;
            } else {
                options.order_id = data.order_id;
                options.amount = data.amount;
                options.currency = data.currency || "{$currency}";
            }
            var rzp = new Razorpay(options);
            rzp.open();
        });
    });
})();
</script>
HTML;
    }

    /**
     * Verify a client-side Razorpay checkout result before crediting an invoice.
     * Confirms the signature (proves the order/payment pair came from Razorpay
     * for our account), that the order belongs to THIS invoice, and uses the
     * amount Razorpay recorded — never a client-supplied value.
     */
    public function verifyPayment(string $orderId, string $paymentId, string $signature, int $expectedInvoiceId): array
    {
        $keyId = $this->getSetting('key_id');
        $keySecret = $this->getSetting('key_secret');
        if (!$keyId || !$keySecret) {
            return ['success' => false, 'message' => 'Razorpay credentials not configured.'];
        }
        if (!$orderId || !$paymentId || !$signature) {
            return ['success' => false, 'message' => 'Missing Razorpay payment fields.'];
        }

        $expected = hash_hmac('sha256', "{$orderId}|{$paymentId}", $keySecret);
        if (!hash_equals($expected, $signature)) {
            Log::warning('Razorpay: checkout signature verification failed', ['order' => $orderId, 'payment' => $paymentId]);
            return ['success' => false, 'message' => 'Invalid payment signature.'];
        }

        // Confirm the order belongs to this invoice and read the authoritative amount.
        $orderResp = Http::withBasicAuth($keyId, $keySecret)->get("{$this->apiUrl}/orders/{$orderId}");
        if (!$orderResp->successful()) {
            return ['success' => false, 'message' => 'Razorpay: order lookup failed.'];
        }
        $order = $orderResp->json();
        $orderInvoiceId = (int) ($order['notes']['invoice_id'] ?? 0);
        if ($orderInvoiceId !== $expectedInvoiceId) {
            Log::warning('Razorpay: order invoice mismatch', ['expected' => $expectedInvoiceId, 'actual' => $orderInvoiceId]);
            return ['success' => false, 'message' => 'Payment does not match this invoice.'];
        }
        if (($order['status'] ?? null) !== 'paid') {
            return ['success' => false, 'message' => 'Payment not completed.'];
        }

        return [
            'success'        => true,
            'transaction_id' => $paymentId,
            'amount'         => (int) ($order['amount_paid'] ?? $order['amount'] ?? 0) / 100,
        ];
    }

    public function processWebhook(array $data): array
    {
        $webhookSecret = $this->getSetting('webhook_secret');
        $rawPayload = $data['_raw_payload'] ?? '';
        $sigHeader = $data['_signature_header'] ?? '';

        // r170-unsigned: prove who sent this before acting on it.
        //
        // Verification used to run only when a webhook secret happened to be
        // configured. Without one, an unsigned POST to the public webhook URL
        // naming an invoice id in its notes marked that invoice paid - and
        // payment then does everything payment does: the order is accepted, the
        // service provisioned, a suspended one switched back on. The
        // Authorize.net module already refuses in exactly this case.
        if (!$webhookSecret || !$rawPayload || !$sigHeader) {
            Log::warning('Razorpay: webhook refused - no signature to check against');
            return ['success' => false, 'message' => 'Unsigned webhook.'];
        }

        $expected = hash_hmac('sha256', $rawPayload, $webhookSecret);
        if (!hash_equals($expected, $sigHeader)) {
            Log::warning('Razorpay: webhook signature verification failed');
            return ['success' => false, 'message' => 'Invalid webhook signature.'];
        }

        $event = $data['event'] ?? '';

        if (str_starts_with((string) $event, 'subscription.')) {
            return $this->processSubscriptionWebhook((string) $event, $data);
        }

        if ($event !== 'payment.captured') {
            return ['success' => true, 'message' => "Event ignored: {$event}"];
        }

        $payment = $data['payload']['payment']['entity'] ?? [];
        $paymentId = (string) ($payment['id'] ?? '');
        if ($paymentId === '') {
            return ['success' => false, 'message' => 'Webhook payment id missing.'];
        }

        $verified = $this->fetchCapturedPayment($paymentId);
        if (! ($verified['success'] ?? false)) {
            return $verified;
        }

        $invoiceId = $payment['notes']['invoice_id']
            ?? ($verified['notes']['invoice_id'] ?? null);

        Log::info('Razorpay webhook: payment.captured', [
            'payment_id' => $paymentId,
            'invoice_id' => $invoiceId,
            'amount' => $verified['amount'],
        ]);

        return [
            'success' => true,
            'transaction_id' => $paymentId,
            'invoice_id' => $invoiceId,
            'amount' => $verified['amount'],
            'gateway' => 'razorpay',
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function processSubscriptionWebhook(string $event, array $data): array
    {
        $subId = (string) ($data['payload']['subscription']['entity']['id'] ?? '');

        return match ($event) {
            'subscription.charged' => app(\App\Services\Billing\GatewaySubscriptionService::class)->handleRazorpayCharged($data),
            'subscription.cancelled', 'subscription.completed' => $this->touchSubscription($subId, 'cancelled'),
            'subscription.paused', 'subscription.halted' => $this->touchSubscription($subId, $event === 'subscription.halted' ? 'halted' : 'paused'),
            'subscription.resumed', 'subscription.activated', 'subscription.authenticated' => $this->touchSubscription($subId, $event === 'subscription.authenticated' ? 'authenticated' : 'active'),
            default => ['success' => true, 'message' => "Event ignored: {$event}"],
        };
    }

    /**
     * @return array{success: bool, message: string}
     */
    private function touchSubscription(string $remoteId, string $status): array
    {
        if ($remoteId !== '') {
            app(\App\Services\Billing\GatewaySubscriptionService::class)->markStatus($remoteId, $status);
        }

        return ['success' => true, 'message' => "Subscription {$status}."];
    }
}
