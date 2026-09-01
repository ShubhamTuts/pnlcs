<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class AiApiKey extends Model
{
    protected $fillable = ['client_id', 'name', 'prefix', 'key_hash', 'last_used_at', 'revoked_at'];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function usage(): HasMany
    {
        return $this->hasMany(AiUsageEvent::class);
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    /**
     * Mint a plaintext key once. Only the hash is stored.
     *
     * @return array{model: self, plaintext: string}
     */
    public static function issue(Client $client, string $name = 'Default'): array
    {
        $secret = 'wk_live_'.Str::lower(Str::random(40));
        $model = static::create([
            'client_id' => $client->id,
            'name' => $name,
            'prefix' => substr($secret, 0, 12),
            'key_hash' => hash('sha256', $secret),
        ]);

        return ['model' => $model, 'plaintext' => $secret];
    }

    public static function hashKey(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }
}
