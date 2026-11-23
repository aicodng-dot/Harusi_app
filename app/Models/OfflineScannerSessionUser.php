<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfflineScannerSessionUser extends Model
{
    use HasFactory;

    public const DEVICE_ROLE_MASTER = 'master';
    public const DEVICE_ROLE_CLIENT = 'client';

    protected $fillable = [
        'offline_scanner_session_id', 'scanner_user_id', 'assigned_gate', 'device_role',
        'offline_pin_verifier', 'offline_pin_salt', 'offline_pin_algorithm', 'active',
    ];

    protected $hidden = ['offline_pin_verifier', 'offline_pin_salt'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public static function deviceRoles(): array
    {
        return [self::DEVICE_ROLE_MASTER, self::DEVICE_ROLE_CLIENT];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(OfflineScannerSession::class, 'offline_scanner_session_id');
    }

    public function scannerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scanner_user_id');
    }
}
