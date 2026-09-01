<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiByokCredential extends Model
{
    protected $fillable = ['client_id', 'provider', 'base_url', 'api_key', 'enabled'];

    protected $hidden = ['api_key'];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'enabled' => 'boolean',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function providers(): array
    {
        return [
            'openai' => 'https://api.openai.com/v1',
            'groq' => 'https://api.groq.com/openai/v1',
            'openrouter' => 'https://openrouter.ai/api/v1',
            'together' => 'https://api.together.xyz/v1',
            'custom' => '',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public static function activeFor(Client $client): ?self
    {
        return static::where('client_id', $client->id)->where('enabled', true)->first();
    }

    /**
     * @return array{url: string, key: string}
     */
    public function upstream(): array
    {
        $defaults = self::providers();
        $url = rtrim((string) ($this->base_url ?: ($defaults[$this->provider] ?? '')), '/');

        return ['url' => $url, 'key' => (string) $this->api_key];
    }

    public function lastFour(): string
    {
        $key = (string) $this->api_key;

        return $key === '' ? '' : substr($key, -4);
    }
}
