<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiWallet extends Model
{
    protected $fillable = ['client_id', 'balance'];

    protected function casts(): array
    {
        return ['balance' => 'decimal:4'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function ledger(): HasMany
    {
        return $this->hasMany(AiLedgerEntry::class, 'client_id', 'client_id');
    }
}
