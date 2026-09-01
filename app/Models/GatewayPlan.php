<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GatewayPlan extends Model
{
    protected $fillable = [
        'gateway', 'remote_id', 'name', 'period', 'interval', 'amount_subunit', 'currency',
    ];

    protected function casts(): array
    {
        return [
            'interval' => 'integer',
            'amount_subunit' => 'integer',
        ];
    }
}
