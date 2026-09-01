<?php

namespace App\Listeners;

use App\Events\InvoicePaid;
use App\Models\AiCreditPack;
use App\Models\AiLedgerEntry;
use App\Models\InvoiceItem;
use App\Services\Webkahost\AiCreditService;

class CreditAiPurchasesListener
{
    public function handleInvoicePaid(InvoicePaid $event): void
    {
        $invoice = $event->invoice->loadMissing('items', 'client');
        $client = $invoice->client;
        if (! $client) {
            return;
        }

        $items = $invoice->items->filter(fn (InvoiceItem $item) => $item->type === 'AiCredits');
        if ($items->isEmpty()) {
            return;
        }

        $credits = app(AiCreditService::class);

        foreach ($items as $item) {
            $already = AiLedgerEntry::where('invoice_id', $invoice->id)
                ->where('type', 'purchase')
                ->where('description', (string) $item->description)
                ->exists();
            if ($already) {
                continue;
            }
            $pack = AiCreditPack::find((int) $item->rel_id);
            if ($pack) {
                $credits->applyPurchase($client, $pack, $invoice->id);

                continue;
            }

            $amount = (int) $item->rel_id;
            if ($amount > 0) {
                $credits->applyInvoiceCredits($client, $amount, $invoice->id, (string) $item->description);
            }
        }
    }
}
