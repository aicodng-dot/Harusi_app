<?php

namespace App\Services;

use App\Models\Event;
use App\Models\OfflineScannerPackage;
use App\Models\OfflineScannerPackageGuestSnapshot;
use App\Models\OfflineScannerSession;
use App\Models\OfflineScannerSessionUser;
use App\Models\User;
use App\Services\Tenancy\LegacyOfflinePackageGuard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OfflineScannerPackageBuilder
{
    public function __construct(
        private readonly OfflineEventLockService $locks,
        private readonly OfflineScannerSessionService $sessions,
        private readonly GuestSnapshotService $snapshots,
        private readonly OfflineScannerPackageValidator $validator,
        private readonly OfflineScannerPackageCryptoService $crypto,
        private readonly PackageSignatureService $signatures,
        private readonly CanonicalJson $json,
        private readonly OfflineScannerFileStorage $files,
        private readonly LegacyOfflinePackageGuard $legacyGuard,
    ) {}

    public function generate(Event $event, User $actor, array $input): OfflineScannerPackageExport
    {
        $storagePath = null;

        try {
            return DB::transaction(function () use ($event, $actor, $input, &$storagePath): OfflineScannerPackageExport {
                $guarded = $this->legacyGuard->lock($event, $actor, $input);
                $lockedEvent = $guarded['event'];
                $actor = $guarded['actor'];
                $masterUser = $guarded['master'];
                $clientUsers = $guarded['clients'];
                $this->locks->assertEventCanStartOfflineSession($lockedEvent);
                $expiresAt = Carbon::parse($input['package_expires_at']);
                $createdAt = now();

                $guests = $this->snapshots->build($lockedEvent);
                $this->validator->validateGuestSnapshot($lockedEvent, $guests);
                $snapshotVersion = $this->snapshots->version($guests);
                $secrets = $this->generateSessionSecrets();

                $session = $this->sessions->createDraft($lockedEvent, $actor, [
                    'schema_version' => '1.0',
                    'master_scanner_user_id' => $masterUser->id,
                    'master_gate_name' => (string) $input['master_gate_name'],
                    'package_created_at' => $createdAt,
                    'package_expires_at' => $expiresAt,
                    'guest_snapshot_version' => $snapshotVersion,
                    'notes' => $input['session_notes'] ?? null,
                    'session_bootstrap_secret_encrypted' => $this->encryptSecret($secrets['session_bootstrap_secret']),
                    'result_file_encryption_secret_encrypted' => $this->encryptSecret($secrets['result_file_encryption_secret']),
                    'result_file_authentication_secret_encrypted' => $this->encryptSecret($secrets['result_file_authentication_secret']),
                    'device_pairing_secret_encrypted' => $this->encryptSecret($secrets['device_pairing_secret']),
                ]);

                $sessionUsers = $this->createSessionUsers($session, $masterUser, (string) $input['master_gate_name'], $clientUsers);
                $packageUuid = (string) Str::uuid();
                $payload = $this->payload($lockedEvent, $session, $packageUuid, $sessionUsers, $guests, $secrets);
                $envelope = $this->crypto->encryptPayload($payload, (string) $input['package_password']);
                $envelope = [
                    ...$envelope,
                    ...$this->signatures->sign($envelope),
                ];
                $contents = $this->json->encode($envelope);
                $fingerprint = hash('sha256', $contents);
                $fileName = $this->fileName($lockedEvent);
                $storagePath = $this->files->packagePath($session, $fileName);
                $this->files->put($storagePath, $contents);

                $package = OfflineScannerPackage::query()->create([
                    'package_uuid' => $packageUuid,
                    'offline_scanner_session_id' => $session->id,
                    'file_name' => $fileName,
                    'file_path' => $storagePath,
                    'storage_disk' => $this->files->diskName(),
                    'internal_format' => $this->files->packageFormat(),
                    'file_hash' => $fingerprint,
                    'encrypted_file_size' => strlen($contents),
                    'encryption_algorithm' => $envelope['encryption_algorithm'],
                    'password_kdf_algorithm' => $envelope['password_kdf_algorithm'],
                    'password_kdf_parameters' => $envelope['password_kdf_parameters'],
                    'signature_algorithm' => $envelope['signature_algorithm'],
                    'signature_key_id' => $envelope['signature_key_id'],
                    'package_fingerprint' => $fingerprint,
                    'generated_by_user_id' => $actor->id,
                    'generated_at' => $createdAt,
                ]);
                $this->storeGuestSnapshot($session, $package, $guests);

                $session->update([
                    'package_export_id' => $package->id,
                    'package_fingerprint' => $fingerprint,
                ]);

                $session = $this->sessions->transition($session->fresh(), OfflineScannerSession::STATUS_PACKAGE_GENERATED, $actor);
                $session = $this->locks->activateLock($session, $actor);

                return new OfflineScannerPackageExport(
                    $session->load(['event', 'createdBy', 'masterScannerUser', 'sessionUsers.scannerUser', 'packageExport']),
                    $package->fresh(),
                    $contents,
                    $envelope,
                );
            }, 3);
        } catch (\Throwable $exception) {
            if ($storagePath && $this->files->exists($storagePath)) {
                $this->files->delete($storagePath);
            }
            throw $exception;
        }
    }

    private function generateSessionSecrets(): array
    {
        return [
            'session_bootstrap_secret' => random_bytes(32),
            'result_file_encryption_secret' => random_bytes(32),
            'result_file_authentication_secret' => random_bytes(32),
            'device_pairing_secret' => random_bytes(32),
        ];
    }

    private function encryptSecret(string $secret): string
    {
        return Crypt::encryptString(base64_encode($secret));
    }

    private function createSessionUsers(OfflineScannerSession $session, User $masterUser, string $masterGate, array $clientUsers): array
    {
        $rows = [];
        $rows[] = $this->createSessionUser($session, $masterUser, $masterGate, OfflineScannerSessionUser::DEVICE_ROLE_MASTER);

        foreach ($clientUsers as $client) {
            $rows[] = $this->createSessionUser(
                $session,
                $client['user'],
                $client['assigned_gate'],
                OfflineScannerSessionUser::DEVICE_ROLE_CLIENT,
            );
        }

        return $rows;
    }

    private function createSessionUser(OfflineScannerSession $session, User $user, string $gate, string $role): OfflineScannerSessionUser
    {
        $salt = random_bytes(16);

        return OfflineScannerSessionUser::query()->create([
            'offline_scanner_session_id' => $session->id,
            'scanner_user_id' => $user->id,
            'assigned_gate' => $gate,
            'device_role' => $role,
            'offline_pin_verifier' => base64_encode(hash_pbkdf2('sha256', $user->id.'|'.$session->session_uuid, $salt, 120000, 32, true)),
            'offline_pin_salt' => base64_encode($salt),
            'offline_pin_algorithm' => 'PBKDF2-HMAC-SHA256:120000',
            'active' => true,
        ])->load('scannerUser');
    }

    private function payload(
        Event $event,
        OfflineScannerSession $session,
        string $packageUuid,
        array $sessionUsers,
        array $guests,
        array $secrets,
    ): array {
        return [
            'schema_version' => '1.0',
            'package_export_id' => $packageUuid,
            'session' => [
                'session_uuid' => $session->session_uuid,
                'package_export_id' => $packageUuid,
                'package_created_at' => $session->package_created_at?->toJSON(),
                'package_expires_at' => $session->package_expires_at?->toJSON(),
                'guest_snapshot_version' => $session->guest_snapshot_version,
                'protocol_version' => (string) config('services.offline_scanner_package.protocol_version', '1.0'),
                'minimum_android_app_version' => (string) config('services.offline_scanner_package.minimum_android_app_version', '1.0.0'),
            ],
            'event' => $this->eventPayload($event),
            'scanner_users' => array_map(fn (OfflineScannerSessionUser $sessionUser): array => $this->scannerUserPayload($sessionUser), $sessionUsers),
            'guests' => $guests,
            'security' => [
                'hash_algorithm' => 'SHA-256',
                'session_bootstrap_secret' => base64_encode($secrets['session_bootstrap_secret']),
                'result_file_encryption_secret' => base64_encode($secrets['result_file_encryption_secret']),
                'result_file_authentication_secret' => base64_encode($secrets['result_file_authentication_secret']),
                'device_pairing_secret' => base64_encode($secrets['device_pairing_secret']),
            ],
            'snapshot' => [
                'guest_count' => count($guests),
                'snapshot_hash' => hash('sha256', $this->json->encode($guests)),
                'created_at' => $session->package_created_at?->toJSON(),
            ],
        ];
    }

    private function storeGuestSnapshot(OfflineScannerSession $session, OfflineScannerPackage $package, array $guests): void
    {
        foreach ($guests as $guest) {
            OfflineScannerPackageGuestSnapshot::query()->create([
                'offline_scanner_session_id' => $session->id,
                'offline_scanner_package_id' => $package->id,
                'guest_id' => ctype_digit((string) ($guest['external_guest_id'] ?? ''))
                    ? (int) $guest['external_guest_id']
                    : null,
                'external_guest_id' => (string) $guest['external_guest_id'],
                'record_version' => (string) $guest['record_version'],
                'pass_type' => (string) ($guest['pass_type'] ?? ''),
                'guest_status' => (string) ($guest['guest_status'] ?? ''),
                'manual_code_hash' => empty($guest['manual_code']) ? '' : hash('sha256', (string) $guest['manual_code']),
                'qr_token_hash' => (string) ($guest['qr_token_hash'] ?? ''),
                'allowed_entries' => (int) $guest['allowed_entries'],
                'used_entries' => (int) $guest['used_entries'],
                'remaining_entries' => (int) $guest['remaining_entries'],
            ]);
        }
    }

    private function eventPayload(Event $event): array
    {
        $timezone = config('app.timezone', 'UTC');
        $eventDate = $event->event_date;

        return [
            'event_id' => (int) $event->id,
            'event_name' => (string) $event->event_name,
            'venue' => (string) ($event->venue_name ?? ''),
            'event_start' => $eventDate ? $eventDate->copy()->startOfDay()->timezone($timezone)->toJSON() : null,
            'event_end' => $eventDate ? $eventDate->copy()->endOfDay()->timezone($timezone)->toJSON() : null,
            'timezone' => $timezone,
            'event_status' => (string) $event->status,
        ];
    }

    private function scannerUserPayload(OfflineScannerSessionUser $sessionUser): array
    {
        $role = (string) $sessionUser->device_role;

        return [
            'scanner_user_id' => (int) $sessionUser->scanner_user_id,
            'display_name' => (string) $sessionUser->scannerUser?->name,
            'assigned_gate' => (string) $sessionUser->assigned_gate,
            'role' => $role,
            'offline_pin_verifier' => (string) $sessionUser->offline_pin_verifier,
            'offline_pin_salt' => (string) $sessionUser->offline_pin_salt,
            'offline_pin_algorithm' => (string) $sessionUser->offline_pin_algorithm,
            'permissions' => $role === OfflineScannerSessionUser::DEVICE_ROLE_MASTER
                ? ['scan', 'admit', 'sync', 'pair_clients', 'export_results']
                : ['scan', 'admit', 'sync_to_master'],
        ];
    }

    private function fileName(Event $event): string
    {
        $date = $event->event_date?->format('Y-m-d') ?? now()->format('Y-m-d');
        $slug = (string) Str::of($event->event_name)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '-')
            ->trim('-')
            ->limit(80, '');

        if ($slug === '') {
            $slug = 'event';
        }

        return $slug.'-offline-scanner-'.$date.'.wqrpkg';
    }
}
