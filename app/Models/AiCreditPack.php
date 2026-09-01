<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiCreditPack extends Model
{
    protected $fillable = ['slug', 'name', 'credits', 'price', 'featured', 'sort_order', 'active'];

    protected function casts(): array
    {
        return [
            'credits' => 'integer',
            'price' => 'decimal:2',
            'featured' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public function scopeOffered($query)
    {
        return $query->where('active', true)->orderBy('sort_order')->orderBy('id');
    }
}
