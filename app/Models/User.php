<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Services\PhoneNumberService;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public const ROLE_ADMIN = 'admin';

    public const ROLE_SCANNER = 'scanner';

    public const ROLE_CUSTOMER = 'customer';

    public const SUPPORTED_LANGUAGES = ['sw', 'en'];

    protected $attributes = [
        'is_active' => true,
        'preferred_language' => 'sw',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone_number',
        'password',
        'is_active',
        'preferred_language',
        'role',
        'event_id',
        'gate_name',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'is_platform_admin',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'is_platform_admin' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (User $user): void {
            if ($user->role === self::ROLE_SCANNER && ! $user->event_id) {
                $user->event_id = Event::defaultEvent()->id;
            }
        });
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function canAuthenticate(): bool
    {
        // Customer scope must never use an unsigned ID clamped by Eloquent's
        // integer cast as a different account's workspace identity.
        if ($this->isCustomer() && ! $this->hasSafeIdentityId()) {
            return false;
        }

        return $this->is_active && in_array($this->role, [self::ROLE_ADMIN, self::ROLE_SCANNER, self::ROLE_CUSTOMER], true);
    }

    public function setPhoneNumberAttribute(?string $phoneNumber): void
    {
        $this->attributes['phone_number'] = $phoneNumber === null || trim($phoneNumber) === ''
            ? null
            : app(PhoneNumberService::class)->normalizeOrFail($phoneNumber);
    }

    public function isScanner(): bool
    {
        return $this->role === self::ROLE_SCANNER;
    }

    public function isCustomer(): bool
    {
        return $this->role === self::ROLE_CUSTOMER;
    }

    public function canUseDeviceTokens(): bool
    {
        return $this->canAuthenticate() && ($this->isAdmin() || $this->isScanner());
    }

    public function hasSafeIdentityId(): bool
    {
        $raw = $this->attributes[$this->getKeyName()] ?? null;

        return (is_int($raw) && $raw > 0)
            || (is_string($raw) && preg_match('/\A[1-9][0-9]*\z/', $raw) === 1
                && filter_var($raw, FILTER_VALIDATE_INT) !== false);
    }

    public function checkins(): HasMany
    {
        return $this->hasMany(Checkin::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_users')
            ->withPivot(['id', 'role', 'is_active'])->withTimestamps();
    }

    public function createdOfflineScannerSessions(): HasMany
    {
        return $this->hasMany(OfflineScannerSession::class, 'created_by_user_id');
    }

    public function masterOfflineScannerSessions(): HasMany
    {
        return $this->hasMany(OfflineScannerSession::class, 'master_scanner_user_id');
    }

    public function offlineScannerSessionUsers(): HasMany
    {
        return $this->hasMany(OfflineScannerSessionUser::class, 'scanner_user_id');
    }

    public function generatedOfflineScannerPackages(): HasMany
    {
        return $this->hasMany(OfflineScannerPackage::class, 'generated_by_user_id');
    }

    public function uploadedOfflineScannerResultImports(): HasMany
    {
        return $this->hasMany(OfflineScannerResultImport::class, 'uploaded_by_user_id');
    }

    public function offlineScannerImportedLogs(): HasMany
    {
        return $this->hasMany(OfflineScannerImportedLog::class, 'scanner_user_id');
    }

    public function resolvedOfflineScannerImportConflicts(): HasMany
    {
        return $this->hasMany(OfflineScannerImportConflict::class, 'resolved_by_user_id');
    }
}
