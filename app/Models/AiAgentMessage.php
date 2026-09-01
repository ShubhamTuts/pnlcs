<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiAgentMessage extends Model
{
    protected $fillable = ['client_id', 'role', 'content', 'tool_calls'];

    protected function casts(): array
    {
        return ['tool_calls' => 'array'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
