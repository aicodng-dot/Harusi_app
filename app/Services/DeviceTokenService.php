<?php

namespace App\Services;

use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\NewAccessToken;

class DeviceTokenService
{
    public const ABILITIES = ['auth:read', 'auth:logout'];

    public const MAX_TOKENS = 10;

    public function hasActiveAssignment(User $user): bool
    {
        return $user->isAdmin() || ($user->isScanner() && $user->event_id !== null
            && Event::query()->whereKey($user->event_id)->where('status', Event::STATUS_ACTIVE)->exists());
    }

    public function issue(array $identity, string $password, string $deviceName): ?NewAccessToken
    {
        // This is the same user-row boundary used by recovery and admin edits.
        // Credentials must be checked after acquiring it, never before the lock.
        return (new User)->getConnection()->transaction(function () use ($identity, $password, $deviceName): ?NewAccessToken {
            $user = User::query()->where($identity)->lockForUpdate()->first();

            if (! $user || ! Hash::check($password, $user->getAuthPassword())
                || ! $user->canUseDeviceTokens() || ! $this->hasActiveAssignment($user)) {
                return null;
            }

            $lifetime = (int) config('sanctum.expiration');
            $user->tokens()->where(function ($query) use ($lifetime): void {
                $query->where('expires_at', '<=', now())->orWhere('created_at', '<=', now()->subMinutes($lifetime));
            })->delete();

            $keep = $user->tokens()->orderByDesc('created_at')->orderByDesc('id')->limit(self::MAX_TOKENS - 1)->pluck('id');
            $user->tokens()->whereNotIn('id', $keep)->delete();

            $token = $user->createToken($deviceName, self::ABILITIES, now()->addMinutes($lifetime));
            $token->accessToken->setRelation('tokenable', $user);

            return $token;
        });
    }

    public function profile(User $user): array
    {
        return $user->only(['id', 'name', 'email', 'phone_number', 'preferred_language', 'role', 'event_id', 'gate_name']);
    }
}
