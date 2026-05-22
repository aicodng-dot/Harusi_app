<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Admission extends Model
{
    use HasFactory;

    protected $fillable = [
        'guest_id',
        'quantity',
        'admitted_by',
        'device_label',
        'ip_address',
        'user_agent',
        'admitted_at',
    ];

    protected function casts(): array
    {
        return [
            'admitted_at' => 'datetime',
        ];
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }
}
