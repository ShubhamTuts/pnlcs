<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Concerns\ResolvesClient;
use App\Http\Controllers\Controller;
use App\Models\AiApiKey;
use App\Models\AiCreditPack;
use App\Models\AiUsageEvent;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\InvoiceService;
use App\Services\Module\ModuleRegistry;
use App\Services\Webkahost\AiCreditService;
use Illuminate\Http\Request;

class AiCreditController extends Controller
{
    use ResolvesClient;

    public function index(AiCreditService $credits)
    {
        $client = $this->currentClient();
        abort_unless($client, 403);

        $packs = AiCreditPack::offered()->get();
        $keys = AiApiKey::where('client_id', $client->id)->orderByDesc('id')->get();
        $usage = AiUsageEvent::where('client_id', $client->id)->orderByDesc('id')->limit(25)->get();
        $gateways = collect(app(ModuleRegistry::class)->usableGateways())->sort()->values();

        return view('client.ai.index', [
            'client' => $client,
            'balance' => $credits->balance($client),
            'packs' => $packs,
            'keys' => $keys,
            'usage' => $usage,
            'gateways' => $gateways,
            'models' => AiCreditService::catalogue(),
        ]);
    }

    public function purchase(Request $request)
    {
        $validated = $request->validate([
            'pack' => 'required|string|max:64',
            'payment_method' => 'required|string|max:50',
        ]);

        $client = $this->currentClient();
        abort_unless($client, 403);

        $pack = AiCreditPack::offered()->where('slug', $validated['pack'])->first();
        if (! $pack) {
            return back()->with('error', __('client.ai.pack_missing'));
        }

        if (! in_array($validated['payment_method'], app(ModuleRegistry::class)->usableGateways(), true)) {
            return back()->with('error', __('messages.error.gateway_not_configured', ['gateway' => ucfirst($validated['payment_method'])]));
        }

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
            'payment_method' => $validated['payment_method'],
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

        return redirect()->route('client.invoices.show', $invoice)
            ->with('success', __('client.ai.invoice_created'));
    }

    public function storeKey(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:120',
        ]);

        $client = $this->currentClient();
        abort_unless($client, 403);

        $issued = AiApiKey::issue($client, $validated['name']);

        return back()
            ->with('success', __('client.ai.key_created'))
            ->with('ai_plaintext_key', $issued['plaintext']);
    }

    public function revokeKey(AiApiKey $key)
    {
        $client = $this->currentClient();
        abort_unless($client && $key->client_id === $client->id, 403);

        $key->forceFill(['revoked_at' => now()])->save();

        return back()->with('success', __('client.ai.key_revoked'));
    }
}
