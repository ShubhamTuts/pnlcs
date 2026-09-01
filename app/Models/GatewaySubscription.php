<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GatewaySubscription extends Model
{
    public const LIVE = ['created', 'authenticated', 'active', 'pending', 'paused', 'halted'];

    protected $fillable = [
        'client_id', 'invoice_id', 'service_id', 'ai_credit_pack_id',
        'gateway', 'remote_plan_id', 'remote_id', 'status', 'period', 'interval',
        'amount', 'currency', 'total_count', 'paid_count', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'interval' => 'integer',
            'total_count' => 'integer',
            'paid_count' => 'integer',
            'meta' => 'array',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function pack(): BelongsTo
    {
        return $this->belongsTo(AiCreditPack::class, 'ai_credit_pack_id');
    }

    public function isLive(): bool
    {
        return in_array($this->status, self::LIVE, true);
    }
}
