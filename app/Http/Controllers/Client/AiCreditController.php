<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Concerns\ResolvesClient;
use App\Http\Controllers\Controller;
use App\Models\AiApiKey;
use App\Models\AiByokCredential;
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
            'byok' => AiByokCredential::where('client_id', $client->id)->first(),
            'byokProviders' => AiByokCredential::providers(),
        ]);
    }

    public function purchase(Request $request)
    {
        $validated = $request->validate([
            'pack' => 'required|string|max:64',
            'payment_method' => 'required|string|max:50',
            'subscribe' => 'sometimes|boolean',
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
            'notes' => ($validated['payment_method'] === 'razorpay' && $request->boolean('subscribe'))
                ? 'razorpay_subscribe=1'
                : null,
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

    public function saveByok(Request $request)
    {
        $validated = $request->validate([
            'provider' => 'required|in:'.implode(',', array_keys(AiByokCredential::providers())),
            'api_key' => 'required|string|min:8|max:512',
            'base_url' => 'nullable|url|max:255',
        ]);

        $client = $this->currentClient();
        abort_unless($client, 403);

        $base = rtrim((string) ($validated['base_url'] ?? ''), '/');
        if ($validated['provider'] === 'custom' && $base === '') {
            return back()->with('error', __('client.ai.byok_url_required'));
        }

        if ($base !== '' && ! $this->byokUrlAllowed($base)) {
            return back()->with('error', __('client.ai.byok_url_https'));
        }

        AiByokCredential::updateOrCreate(
            ['client_id' => $client->id],
            [
                'provider' => $validated['provider'],
                'base_url' => $base !== '' ? $base : null,
                'api_key' => $validated['api_key'],
                'enabled' => true,
            ]
        );

        return back()->with('success', __('client.ai.byok_saved'));
    }

    public function disableByok()
    {
        $client = $this->currentClient();
        abort_unless($client, 403);

        AiByokCredential::where('client_id', $client->id)->update(['enabled' => false]);

        return back()->with('success', __('client.ai.byok_disabled'));
    }

    private function byokUrlAllowed(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (in_array($host, ['localhost', '127.0.0.1'], true) || str_ends_with($host, '.test')) {
            return in_array($scheme, ['http', 'https'], true);
        }

        return $scheme === 'https';
    }
}
