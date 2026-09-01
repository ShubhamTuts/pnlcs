<?php

use App\Models\AiCreditPack;
use App\Models\Client;
use App\Models\GatewaySettings;
use App\Models\GatewaySubscription;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Service;
use App\Models\User;
use App\Services\Billing\GatewaySubscriptionService;
use App\Services\InvoiceGenerationService;
use Illuminate\Support\Facades\Http;

function rzpKeys(): void
{
    foreach (['key_id' => 'rzp_test_key', 'key_secret' => 'rzp_secret', 'webhook_secret' => 'rzp_hook', 'enable_subscriptions' => '1', 'active' => '1'] as $k => $v) {
        GatewaySettings::updateOrCreate(
            ['gateway' => 'razorpay', 'setting' => $k],
            ['value' => $v]
        );
    }
}

function rzpPayer(Client $client): User
{
    $user = User::factory()->create();
    $user->clients()->attach($client->id);

    return $user;
}

function rzpHostingInvoice(): array
{
    $client = Client::factory()->create();
    $product = Product::factory()->create([
        'group_id' => ProductGroup::factory()->create()->id,
        'pay_type' => 'recurring',
    ]);
    $service = Service::factory()->create([
        'client_id' => $client->id,
        'product_id' => $product->id,
        'billing_cycle' => 'Monthly',
        'amount' => 10,
        'status' => 'active',
        'auto_renew' => true,
    ]);
    $invoice = Invoice::factory()->create([
        'client_id' => $client->id,
        'status' => 'unpaid',
        'subtotal' => 10,
        'total' => 10,
        'payment_method' => 'razorpay',
    ]);
    InvoiceItem::create([
        'invoice_id' => $invoice->id,
        'client_id' => $client->id,
        'type' => 'Hosting',
        'rel_id' => $service->id,
        'description' => 'Hosting',
        'amount' => 10,
        'taxed' => false,
    ]);

    return [$client, $service, $invoice->fresh('items')];
}

beforeEach(function () {
    rzpKeys();
});

it('creates a razorpay plan and subscription for a recurring hosting invoice', function () {
    Http::fake([
        '*/v1/plans' => Http::response(['id' => 'plan_host_1'], 200),
        '*/v1/subscriptions' => Http::response(['id' => 'sub_host_1', 'status' => 'created'], 200),
    ]);

    [$client, $service, $invoice] = rzpHostingInvoice();

    $this->actingAs(rzpPayer($client))
        ->postJson("/gateway/razorpay/capture/{$invoice->id}")
        ->assertOk()
        ->assertJson(['success' => true, 'subscription_id' => 'sub_host_1']);

    expect(GatewaySubscription::where('remote_id', 'sub_host_1')->where('service_id', $service->id)->exists())->toBeTrue();
});

it('confirms a subscription checkout with payment_id|subscription_id HMAC', function () {
    [$client, $service, $invoice] = rzpHostingInvoice();
    GatewaySubscription::create([
        'client_id' => $client->id,
        'invoice_id' => $invoice->id,
        'service_id' => $service->id,
        'gateway' => 'razorpay',
        'remote_plan_id' => 'plan_x',
        'remote_id' => 'sub_ok',
        'status' => 'created',
        'period' => 'monthly',
        'amount' => 10,
        'currency' => 'USD',
    ]);

    Http::fake([
        '*/v1/subscriptions/sub_ok' => Http::response([
            'id' => 'sub_ok',
            'status' => 'authenticated',
            'notes' => ['invoice_id' => (string) $invoice->id],
        ], 200),
        '*/v1/payments/pay_sub' => Http::response([
            'id' => 'pay_sub',
            'status' => 'captured',
            'amount' => 1000,
        ], 200),
    ]);

    $sig = hash_hmac('sha256', 'pay_sub|sub_ok', 'rzp_secret');

    $this->actingAs(rzpPayer($client))
        ->postJson("/gateway/razorpay/capture/{$invoice->id}", [
            'confirm' => true,
            'razorpay_payment_id' => 'pay_sub',
            'razorpay_subscription_id' => 'sub_ok',
            'razorpay_signature' => $sig,
        ])
        ->assertOk()
        ->assertJson(['success' => true]);

    expect($invoice->fresh()->status)->toBe('paid');
});

it('pays the seed invoice on a signed subscription.charged webhook', function () {
    [$client, $service, $invoice] = rzpHostingInvoice();
    GatewaySubscription::create([
        'client_id' => $client->id,
        'invoice_id' => $invoice->id,
        'service_id' => $service->id,
        'gateway' => 'razorpay',
        'remote_plan_id' => 'plan_x',
        'remote_id' => 'sub_chg',
        'status' => 'active',
        'period' => 'monthly',
        'amount' => 10,
        'currency' => 'USD',
    ]);

    Http::fake([
        '*/v1/payments/pay_cycle' => Http::response([
            'id' => 'pay_cycle',
            'status' => 'captured',
            'amount' => 1000,
        ], 200),
    ]);

    $body = json_encode([
        'event' => 'subscription.charged',
        'payload' => [
            'subscription' => ['entity' => [
                'id' => 'sub_chg',
                'status' => 'active',
                'paid_count' => 1,
                'notes' => ['invoice_id' => (string) $invoice->id, 'service_id' => (string) $service->id],
            ]],
            'payment' => ['entity' => ['id' => 'pay_cycle', 'amount' => 1000]],
        ],
    ]);

    $this->call('POST', '/gateway/razorpay/webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_RAZORPAY_SIGNATURE' => hash_hmac('sha256', $body, 'rzp_hook'),
    ], $body);

    expect($invoice->fresh()->status)->toBe('paid');
});

it('raises a new AI credit invoice on a later subscription.charged', function () {
    $client = Client::factory()->create();
    $pack = AiCreditPack::where('slug', 'starter')->firstOrFail();
    $seed = Invoice::factory()->create([
        'client_id' => $client->id,
        'status' => 'paid',
        'subtotal' => $pack->price,
        'total' => $pack->price,
        'notes' => 'razorpay_subscribe=1',
    ]);
    InvoiceItem::create([
        'invoice_id' => $seed->id,
        'client_id' => $client->id,
        'type' => 'AiCredits',
        'rel_id' => $pack->id,
        'description' => 'seed',
        'amount' => $pack->price,
        'taxed' => false,
    ]);

    $row = GatewaySubscription::create([
        'client_id' => $client->id,
        'invoice_id' => $seed->id,
        'ai_credit_pack_id' => $pack->id,
        'gateway' => 'razorpay',
        'remote_plan_id' => 'plan_ai',
        'remote_id' => 'sub_ai',
        'status' => 'active',
        'period' => 'monthly',
        'amount' => $pack->price,
        'currency' => 'USD',
        'paid_count' => 1,
    ]);

    Http::fake([
        '*/v1/payments/pay_ai2' => Http::response([
            'id' => 'pay_ai2',
            'status' => 'captured',
            'amount' => (int) round($pack->price * 100),
        ], 200),
    ]);

    $body = json_encode([
        'event' => 'subscription.charged',
        'payload' => [
            'subscription' => ['entity' => [
                'id' => 'sub_ai',
                'status' => 'active',
                'paid_count' => 2,
                'notes' => ['invoice_id' => (string) $seed->id, 'pack_id' => (string) $pack->id],
            ]],
            'payment' => ['entity' => ['id' => 'pay_ai2']],
        ],
    ]);

    $this->call('POST', '/gateway/razorpay/webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_RAZORPAY_SIGNATURE' => hash_hmac('sha256', $body, 'rzp_hook'),
    ], $body);

    $renewal = Invoice::where('client_id', $client->id)->where('id', '!=', $seed->id)->latest('id')->first();

    expect($renewal)->not->toBeNull()
        ->and($renewal->status)->toBe('paid')
        ->and($renewal->items()->where('type', 'AiCredits')->where('rel_id', $pack->id)->exists())->toBeTrue();
});

it('skips the invoice cron for a service with a live razorpay subscription', function () {
    [$client, $service, $invoice] = rzpHostingInvoice();
    $invoice->update(['status' => 'paid']);
    $service->update(['next_due_date' => now()->addDay(), 'auto_renew' => true, 'amount' => 10, 'status' => 'active']);
    GatewaySubscription::create([
        'client_id' => $client->id,
        'service_id' => $service->id,
        'gateway' => 'razorpay',
        'remote_id' => 'sub_live',
        'status' => 'active',
        'period' => 'monthly',
        'amount' => 10,
        'currency' => 'USD',
    ]);

    $beforeForService = Invoice::whereHas('items', fn ($q) => $q->where('type', 'Hosting')->where('rel_id', $service->id))->count();
    app(InvoiceGenerationService::class)->generateDueInvoices();

    expect(Invoice::whereHas('items', fn ($q) => $q->where('type', 'Hosting')->where('rel_id', $service->id))->count())->toBe($beforeForService);
});

it('cancels the razorpay subscription when the service is terminated', function () {
    Http::fake(['*/v1/subscriptions/sub_term/cancel' => Http::response(['id' => 'sub_term', 'status' => 'cancelled'], 200)]);

    [$client, $service] = rzpHostingInvoice();
    $row = GatewaySubscription::create([
        'client_id' => $client->id,
        'service_id' => $service->id,
        'gateway' => 'razorpay',
        'remote_id' => 'sub_term',
        'status' => 'active',
        'period' => 'monthly',
        'amount' => 10,
        'currency' => 'USD',
    ]);

    $service->update(['status' => 'terminated']);

    expect($row->fresh()->status)->toBe('cancelled');
});

it('stores a subscribe flag on an AI credit invoice paid with razorpay', function () {
    $client = Client::factory()->create();
    $user = rzpPayer($client);
    $pack = AiCreditPack::where('slug', 'starter')->firstOrFail();

    $this->actingAs($user)
        ->post(route('client.ai.purchase'), [
            'pack' => $pack->slug,
            'payment_method' => 'razorpay',
            'subscribe' => '1',
        ])
        ->assertRedirect();

    $invoice = Invoice::where('client_id', $client->id)->latest('id')->first();

    expect($invoice)->not->toBeNull()
        ->and($invoice->notes)->toContain('razorpay_subscribe=1')
        ->and(app(GatewaySubscriptionService::class)->invoiceWantsRazorpaySubscription($invoice->fresh('items')))->toBeTrue();
});
