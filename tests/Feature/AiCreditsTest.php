<?php

use App\Models\AiApiKey;
use App\Models\AiCreditPack;
use App\Models\AiWallet;
use App\Models\Client;
use App\Models\GatewaySettings;
use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use App\Services\Webkahost\AiCreditService;
use Illuminate\Support\Facades\Mail;

function aiClientUser(): array
{
    $client = Client::factory()->create();
    $user = User::factory()->create();
    $user->clients()->attach($client->id);

    return [$user, $client];
}

function enableBankTransfer(): void
{
    GatewaySettings::updateOrCreate(
        ['gateway' => 'banktransfer', 'setting' => 'active'],
        ['value' => '1']
    );
}

it('credits the AI wallet when an AiCredits invoice is paid', function () {
    Mail::fake();
    $client = Client::factory()->create();
    $pack = AiCreditPack::where('slug', 'starter')->firstOrFail();

    $invoice = app(InvoiceService::class)->createInvoice($client, [
        [
            'type' => 'AiCredits',
            'rel_id' => $pack->id,
            'description' => "Webkahost AI Credits — {$pack->name}",
            'amount' => $pack->price,
            'taxed' => false,
        ],
    ]);

    app(PaymentService::class)->applyPayment($invoice, 'banktransfer', 'TXN-AI-1', (float) $invoice->total);

    expect((float) app(AiCreditService::class)->balance($client->fresh()))->toBe((float) $pack->credits);
});

it('does not credit the same AI invoice twice', function () {
    Mail::fake();
    $client = Client::factory()->create();
    $pack = AiCreditPack::where('slug', 'starter')->firstOrFail();

    $invoice = app(InvoiceService::class)->createInvoice($client, [
        [
            'type' => 'AiCredits',
            'rel_id' => $pack->id,
            'description' => "Webkahost AI Credits — {$pack->name}",
            'amount' => $pack->price,
            'taxed' => false,
        ],
    ]);

    $payments = app(PaymentService::class);
    $payments->applyPayment($invoice, 'banktransfer', 'TXN-AI-DUP', (float) $invoice->total);
    $payments->applyPayment($invoice->fresh(), 'banktransfer', 'TXN-AI-DUP', (float) $invoice->total);

    expect((float) app(AiCreditService::class)->balance($client->fresh()))->toBe((float) $pack->credits);
});

it('refuses a charge the wallet cannot cover', function () {
    $client = Client::factory()->create();
    $credits = app(AiCreditService::class);

    expect($credits->charge($client, 'gpt-4o-mini', 1000, 1000))->toBeNull()
        ->and($credits->balance($client))->toBe(0.0);
});

it('lets a customer with credits call the AI gateway', function () {
    [$user, $client] = aiClientUser();
    app(AiCreditService::class)->credit($client, 100, 'grant', 'Test grant');
    $issued = AiApiKey::issue($client, 'Test');

    $this->postJson('/api/ai/v1/chat/completions', [
        'model' => 'gpt-4o-mini',
        'messages' => [['role' => 'user', 'content' => 'ping']],
    ], ['Authorization' => 'Bearer '.$issued['plaintext']])
        ->assertOk()
        ->assertJsonPath('object', 'chat.completion')
        ->assertJsonPath('usage.webkahost_credits', fn ($v) => $v > 0);

    expect((float) AiWallet::where('client_id', $client->id)->value('balance'))->toBeLessThan(100);
});

it('rejects a missing AI gateway key', function () {
    $this->postJson('/api/ai/v1/chat/completions', [
        'messages' => [['role' => 'user', 'content' => 'ping']],
    ])->assertStatus(401);
});

it('returns 402 when the AI wallet is empty', function () {
    [$user, $client] = aiClientUser();
    $issued = AiApiKey::issue($client, 'Empty');

    $this->postJson('/api/ai/v1/chat/completions', [
        'messages' => [['role' => 'user', 'content' => 'ping']],
    ], ['Authorization' => 'Bearer '.$issued['plaintext']])
        ->assertStatus(402);
});

it('opens the AI credits page and can mint a key', function () {
    [$user, $client] = aiClientUser();

    $this->actingAs($user)
        ->get(route('client.ai.index'))
        ->assertOk()
        ->assertSee('AI credit balance', false);

    $this->actingAs($user)
        ->post(route('client.ai.keys.store'), ['name' => 'CI key'])
        ->assertRedirect()
        ->assertSessionHas('ai_plaintext_key');

    expect(AiApiKey::where('client_id', $client->id)->count())->toBe(1);
});

it('creates an unpaid invoice when a credit pack is purchased', function () {
    enableBankTransfer();
    [$user, $client] = aiClientUser();

    $this->actingAs($user)
        ->post(route('client.ai.purchase'), [
            'pack' => 'starter',
            'payment_method' => 'banktransfer',
        ])
        ->assertRedirect();

    $invoice = Invoice::where('client_id', $client->id)->latest('id')->first();

    expect($invoice)->not->toBeNull()
        ->and($invoice->items()->where('type', 'AiCredits')->exists())->toBeTrue()
        ->and($invoice->status)->toBe('unpaid');
});
