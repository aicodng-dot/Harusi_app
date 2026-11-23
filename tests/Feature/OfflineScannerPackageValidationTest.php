<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Guest;
use App\Services\OfflineScannerPackageValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OfflineScannerPackageValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_manual_codes_block_export(): void
    {
        $event = Event::factory()->create();

        $this->assertSnapshotRejected($event, [
            $this->validSnapshot($event, ['external_guest_id' => 'guest-1', 'manual_code' => '0047', 'qr_token_hash' => hash('sha256', 'qr-1')]),
            $this->validSnapshot($event, ['external_guest_id' => 'guest-2', 'manual_code' => '0047', 'qr_token_hash' => hash('sha256', 'qr-2')]),
        ], 'duplicates manual code');
    }

    public function test_missing_qr_tokens_block_export(): void
    {
        $event = Event::factory()->create();

        $this->assertSnapshotRejected($event, [
            $this->validSnapshot($event, ['qr_token_hash' => '']),
        ], 'missing a secure QR token hash');
    }

    public function test_duplicate_qr_hashes_block_export(): void
    {
        $event = Event::factory()->create();
        $duplicateHash = hash('sha256', 'same-qr-token');

        $this->assertSnapshotRejected($event, [
            $this->validSnapshot($event, ['external_guest_id' => 'guest-1', 'manual_code' => '0047', 'qr_token_hash' => $duplicateHash]),
            $this->validSnapshot($event, ['external_guest_id' => 'guest-2', 'manual_code' => '0048', 'qr_token_hash' => $duplicateHash]),
        ], 'duplicates a QR token hash');
    }

    public function test_used_greater_than_allowed_blocks_export(): void
    {
        $event = Event::factory()->create();

        $this->assertSnapshotRejected($event, [
            $this->validSnapshot($event, [
                'allowed_entries' => 2,
                'used_entries' => 3,
                'remaining_entries' => -1,
            ]),
        ], 'used entries greater than allowed');
    }

    public function test_remaining_mismatch_blocks_export(): void
    {
        $event = Event::factory()->create();

        $this->assertSnapshotRejected($event, [
            $this->validSnapshot($event, [
                'allowed_entries' => 2,
                'used_entries' => 1,
                'remaining_entries' => 2,
            ]),
        ], 'invalid remaining');
    }

    private function validSnapshot(Event $event, array $overrides = []): array
    {
        return array_merge([
            'external_guest_id' => 'guest-1',
            'event_id' => $event->id,
            'display_name' => 'Snapshot Guest',
            'normalized_phone' => '+255700000001',
            'manual_code' => '0047',
            'pass_type' => Guest::PASS_DOUBLE,
            'allowed_entries' => 2,
            'used_entries' => 1,
            'remaining_entries' => 1,
            'guest_status' => Guest::STATUS_PARTIALLY_USED,
            'admission_disabled' => false,
            'qr_token_hash' => hash('sha256', 'qr-token-1'),
            'updated_at' => now()->toJSON(),
            'record_version' => hash('sha256', 'record-1'),
        ], $overrides);
    }

    private function assertSnapshotRejected(Event $event, array $guests, string $messageNeedle): void
    {
        try {
            app(OfflineScannerPackageValidator::class)->validateGuestSnapshot($event, $guests);
            $this->fail('Expected the offline package snapshot validator to reject the guest data.');
        } catch (ValidationException $exception) {
            $messages = implode(' ', $exception->errors()['offline_package'] ?? []);
            $this->assertStringContainsString($messageNeedle, $messages);
        }
    }
}

