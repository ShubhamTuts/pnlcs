<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiUsageEvent extends Model
{
    protected $fillable = [
        'client_id', 'ai_api_key_id', 'source', 'model', 'provider',
        'input_tokens', 'output_tokens', 'credits_charged', 'latency_ms',
        'status', 'request_id',
    ];

    protected function casts(): array
    {
        return [
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'credits_charged' => 'decimal:4',
            'latency_ms' => 'integer',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(AiApiKey::class, 'ai_api_key_id');
    }
}
