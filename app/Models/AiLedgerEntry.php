<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiLedgerEntry extends Model
{
    protected $fillable = ['client_id', 'type', 'credits', 'description', 'invoice_id', 'usage_event_id', 'meta'];

    protected function casts(): array
    {
        return [
            'credits' => 'decimal:4',
            'meta' => 'array',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
