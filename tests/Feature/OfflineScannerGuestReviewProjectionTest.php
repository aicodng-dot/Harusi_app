<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Guest;
use App\Models\OfflineScannerFinalGuestState;
use App\Models\OfflineScannerImportConflict;
use App\Models\OfflineScannerPackage;
use App\Models\OfflineScannerPackageGuestSnapshot;
use App\Models\OfflineScannerResultImport;
use App\Models\OfflineScannerSession;
use App\Models\OfflineScannerSessionUser;
use App\Models\QrCode;
use App\Models\User;
use App\Services\CanonicalJson;
use App\Services\OfflineScannerResults\OfflineScannerResultSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** HTTP read projections only; stored repairs, writers, actor/scanner history and concurrency remain separate. */
class OfflineScannerGuestReviewProjectionTest extends TestCase
{
    use RefreshDatabase;

    private const FOREIGN_NAME = 'PRIVATE-FOREIGN-REVIEW-GUEST-2047';

    private const OWN_HISTORY = 'Own event historical guest state';

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        Storage::fake($this->reviewBaseOfflineScannerDisk());
    }

    #[DataProvider('foreignOwnership')]
    public function test_http_reviews_redact_foreign_current_and_historical_guest_context_without_writes(bool $owned): void
    {
        $f = $this->reviewFixture($owned);
        $foreign = $f['neighborGuest'];
        $state = $f['conflict']->server_state;
        $state['guest_id'] = $foreign->id;
        $state['event_id'] = $f['neighborEvent']->id;
        $state['name'] = self::FOREIGN_NAME;
        $state['allowed_entries'] = 10;
        $state['used_entries'] = 7;
        $state['remaining_entries'] = 3;
        $f['conflict']->update(['guest_id' => $foreign->id, 'replacement_guest_id' => $foreign->id,
            'server_state' => $state, 'server_used' => 7, 'server_remaining' => 3]);
        $f['state']->update(['guest_id' => $foreign->id]);
        $hydrated = [];
        $this->withModelHooks([Guest::class => function (Guest $guest) use ($foreign, &$hydrated): void {
            if ($guest->getKey() === $foreign->getKey()) {
                $hydrated[] = $guest->getKey();
            }
        }], function () use ($f, &$hydrated): void {
            $this->readViews($f, function (TestResponse $response, OfflineScannerImportConflict $projection, $projectedState, bool $detail) use ($f): void {
                $this->assertNullCurrentReferences($projection, $projectedState);
                $this->assertRedactedServer($projection);
                $this->assertPreservedHistoricalPayloads($f['conflict'], $projection);
                $response->assertDontSee(self::FOREIGN_NAME);
                $this->assertServerHtml($response, $detail, false);
            });
            $this->assertSame([], $hydrated, 'HTTP review must not hydrate a foreign Guest before masking its projection.');
        });
    }

    public static function foreignOwnership(): array
    {
        return ['other unowned Event' => [false], 'owned tenant Event' => [true]];
    }

    #[DataProvider('invalidHistoricalStates')]
    public function test_untrusted_stored_server_state_is_redacted_while_current_own_guest_remains_usable(string $kind): void
    {
        $f = $this->reviewFixture();
        $state = $f['conflict']->server_state;
        match ($kind) {
            'foreign_event' => $state['event_id'] = $f['neighborEvent']->id,
            'missing_event' => $state['event_id'] = null,
            'padded_event' => $state['event_id'] = '0'.$f['event']->id,
            'mismatched_guest' => $state['guest_id'] = $f['guestTwo']->id,
            'padded_guest' => $state['guest_id'] = '0'.$f['guestOne']->id,
        };
        $f['conflict']->update(['server_state' => $state]);
        $this->readViews($f, function (TestResponse $response, OfflineScannerImportConflict $projection, $projectedState, bool $detail) use ($f): void {
            $this->assertOwnCurrentReferences($f, $projection, $projectedState);
            $this->assertRedactedServer($projection);
            $this->assertPreservedHistoricalPayloads($f['conflict'], $projection);
            $this->assertServerHtml($response, $detail, false);
        });
    }

    public static function invalidHistoricalStates(): array
    {
        return ['foreign Event' => ['foreign_event'], 'unknown Event' => ['missing_event'],
            'padded Event' => ['padded_event'], 'mismatched Guest' => ['mismatched_guest'], 'padded Guest' => ['padded_guest']];
    }

    public function test_conflict_child_from_a_different_event_has_no_guest_or_server_projection(): void
    {
        $f = $this->reviewFixture();
        $f['conflict']->update(['event_id' => $f['neighborEvent']->id]);
        $this->readViews($f, function (TestResponse $response, OfflineScannerImportConflict $projection, $projectedState, bool $detail) use ($f): void {
            $this->assertNullCurrentReferences($projection, null);
            $this->assertRedactedServer($projection);
            $this->assertSame($f['guestOne']->id, $projectedState->guest_id);
            $this->assertSame($f['guestOne']->id, $projectedState->guest?->id);
            $this->assertFalse($projectedState->isDirty());
            $this->assertPreservedHistoricalPayloads($f['conflict'], $projection);
            $this->assertServerHtml($response, $detail, false);
        }, ['show', 'logs', 'conflicts']);

        $before = $this->reviewSnapshot();
        $this->get(route('admin.offline-scanner-conflicts.show', $f['conflict']))->assertNotFound();
        $this->assertSame($before, $this->reviewSnapshot(), 'A mismatched conflict-detail head must remain a read-only refusal.');
    }

    public function test_final_state_from_another_session_has_no_guest_projection_even_in_the_same_event(): void
    {
        $f = $this->reviewFixture();
        $otherSession = OfflineScannerSession::factory()->create(['event_id' => $f['event']->id, 'created_by_user_id' => $f['admin']->id]);
        $f['state']->update(['offline_scanner_session_id' => $otherSession->id]);
        $this->readViews($f, function (TestResponse $response, OfflineScannerImportConflict $projection, $projectedState, bool $detail) use ($f): void {
            $this->assertOwnCurrentReferences($f, $projection, null);
            $this->assertSame($f['conflict']->server_state, $projection->server_state);
            $this->assertSame(1, $projection->server_used);
            $this->assertSame(1, $projection->server_remaining);
            if ($projectedState) {
                $this->assertTrue($projectedState->relationLoaded('guest'));
                $this->assertNull($projectedState->getRelation('guest'));
                $this->assertNull($projectedState->guest_id);
                $this->assertFalse($projectedState->isDirty());
            }
            $this->assertPreservedHistoricalPayloads($f['conflict'], $projection);
            $this->assertServerHtml($response, $detail, true);
        });
    }

    public function test_matching_own_current_relations_and_historical_state_are_preserved(): void
    {
        $f = $this->reviewFixture();
        $this->readViews($f, function (TestResponse $response, OfflineScannerImportConflict $projection, $projectedState, bool $detail) use ($f): void {
            $this->assertOwnCurrentReferences($f, $projection, $projectedState);
            $this->assertSame($f['conflict']->server_state, $projection->server_state);
            $this->assertSame(1, $projection->server_used);
            $this->assertSame(1, $projection->server_remaining);
            $this->assertPreservedHistoricalPayloads($f['conflict'], $projection);
            $this->assertServerHtml($response, $detail, true);
        });
    }

    public function test_own_event_historical_state_survives_guest_movement_without_loading_current_foreign_guest(): void
    {
        $f = $this->reviewFixture();
        Guest::query()->whereKey($f['guestOne']->id)->update(['event_id' => $f['neighborEvent']->id, 'name' => self::FOREIGN_NAME]);
        $hydrated = [];
        $this->withModelHooks([Guest::class => function (Guest $guest) use ($f, &$hydrated): void {
            if ($guest->getKey() === $f['guestOne']->getKey()) {
                $hydrated[] = $guest->getKey();
            }
        }], function () use ($f, &$hydrated): void {
            $this->readViews($f, function (TestResponse $response, OfflineScannerImportConflict $projection, $projectedState, bool $detail) use ($f): void {
                $this->assertNull($projection->guest_id);
                $this->assertNull($projection->guest);
                $this->assertSame($f['guestTwo']->id, $projection->replacementGuest?->id);
                if ($projectedState) {
                    $this->assertNull($projectedState->guest_id);
                    $this->assertNull($projectedState->guest);
                }
                $this->assertSame($f['conflict']->server_state, $projection->server_state);
                $this->assertSame(1, $projection->server_used);
                $this->assertSame(1, $projection->server_remaining);
                $response->assertDontSee(self::FOREIGN_NAME);
                $this->assertPreservedHistoricalPayloads($f['conflict'], $projection);
                $this->assertServerHtml($response, $detail, true);
            });
            $this->assertSame([], $hydrated);
        });
    }

    public function test_own_event_historical_state_is_retained_after_guest_deletion(): void
    {
        $f = $this->reviewFixture();
        Guest::query()->whereKey($f['guestOne']->id)->delete();
        $f['conflict']->refresh();
        $this->assertNull($f['conflict']->guest_id);
        $this->readViews($f, function (TestResponse $response, OfflineScannerImportConflict $projection, $projectedState, bool $detail) use ($f): void {
            $this->assertNull($projection->guest_id);
            $this->assertNull($projection->guest);
            $this->assertSame($f['conflict']->server_state, $projection->server_state);
            $this->assertSame(1, $projection->server_used);
            $this->assertSame(1, $projection->server_remaining);
            if ($projectedState) {
                $this->assertNull($projectedState->guest);
            }
            $this->assertPreservedHistoricalPayloads($f['conflict'], $projection);
            $this->assertServerHtml($response, $detail, true);
        });
    }

    public function test_null_bound_guest_reference_can_retain_valid_own_historical_state(): void
    {
        $f = $this->reviewFixture();
        $f['conflict']->update(['guest_id' => null]);
        $this->readViews($f, function (TestResponse $response, OfflineScannerImportConflict $projection, $projectedState, bool $detail) use ($f): void {
            $this->assertNull($projection->guest_id);
            $this->assertNull($projection->guest);
            $this->assertSame($f['conflict']->server_state, $projection->server_state);
            $this->assertSame(1, $projection->server_used);
            $this->assertSame(1, $projection->server_remaining);
            $this->assertPreservedHistoricalPayloads($f['conflict'], $projection);
            $this->assertServerHtml($response, $detail, true);
        });
    }

    #[DataProvider('unsafeReferences')]
    public function test_unsafe_raw_guest_references_cannot_alias_a_real_same_event_maximum_guest(string $kind): void
    {
        $f = $this->reviewFixture();
        Guest::query()->forceCreate(['id' => PHP_INT_MAX, 'event_id' => $f['event']->id, 'name' => 'Legitimate own MAX review option',
            'phone_number' => '+255700009999', 'manual_code' => '9999', 'pass_type' => Guest::PASS_SINGLE, 'allowed_entries' => 1, 'used_entries' => 0]);
        $alias = $kind === 'overflow' ? '9223372036854775808' : (string) PHP_INT_MAX."\n";
        $mutated = 0;
        $hooks = [
            OfflineScannerImportConflict::class => function (OfflineScannerImportConflict $row) use ($f, $alias, &$mutated): void {
                if ($row->getKey() === $f['conflict']->getKey()) {
                    $attributes = $row->getAttributes();
                    $attributes['guest_id'] = $alias;
                    $attributes['replacement_guest_id'] = $alias;
                    $row->setRawAttributes($attributes, true);
                    $mutated++;
                }
            },
            OfflineScannerFinalGuestState::class => function ($row) use ($f, $alias, &$mutated): void {
                if ($row->getKey() === $f['state']->getKey()) {
                    $attributes = $row->getAttributes();
                    $attributes['guest_id'] = $alias;
                    $row->setRawAttributes($attributes, true);
                    $mutated++;
                }
            },
        ];
        $this->withModelHooks($hooks, function () use ($f, &$mutated): void {
            $this->readViews($f, function (TestResponse $response, OfflineScannerImportConflict $projection, $projectedState, bool $detail) use ($f): void {
                $this->assertNullCurrentReferences($projection, $projectedState);
                $this->assertRedactedServer($projection);
                $this->assertPreservedHistoricalPayloads($f['conflict'], $projection);
                $this->assertServerHtml($response, $detail, false);
            });
            $this->assertGreaterThanOrEqual(4, $mutated, 'Unsafe values are injected only into hydrated models, never persisted.');
        });
    }

    public static function unsafeReferences(): array
    {
        return ['overflow' => ['overflow'], 'newline' => ['newline']];
    }

    public function test_preloaded_guest_relations_are_replaced_by_authorized_current_projections(): void
    {
        $f = $this->reviewFixture();
        $hooks = [
            OfflineScannerImportConflict::class => function (OfflineScannerImportConflict $row) use ($f): void {
                if ($row->getKey() === $f['conflict']->getKey()) {
                    $row->setRelation('guest', $f['neighborGuest']);
                    $row->setRelation('replacementGuest', $f['neighborGuest']);
                }
            },
            OfflineScannerFinalGuestState::class => function ($row) use ($f): void {
                if ($row->getKey() === $f['state']->getKey()) {
                    $row->setRelation('guest', $f['neighborGuest']);
                }
            },
        ];
        $this->withModelHooks($hooks, function () use ($f): void {
            $this->readViews($f, function (TestResponse $response, OfflineScannerImportConflict $projection, $projectedState, bool $detail) use ($f): void {
                $this->assertOwnCurrentReferences($f, $projection, $projectedState);
                $this->assertSame($f['conflict']->server_state, $projection->server_state);
                $response->assertDontSee(self::FOREIGN_NAME);
                $this->assertPreservedHistoricalPayloads($f['conflict'], $projection);
                $this->assertServerHtml($response, $detail, true);
            });
        });
    }

    private function reviewFixture(bool $owned = true): array
    {
        $f = $this->reviewBaseFixture();
        if ($owned) {
            DB::table('workspaces')->insert(['id' => 101, 'name' => 'Synthetic review neighbor workspace']);
        }
        $f['neighborEvent'] = Event::factory()->create(['workspace_id' => $owned ? 101 : null, 'event_name' => 'Synthetic foreign review Event']);
        $f['neighborGuest'] = $this->reviewBaseGuest($f['neighborEvent'], self::FOREIGN_NAME, '+255700009030', Guest::PASS_SPECIAL, 10, '9030');
        Storage::disk($this->reviewBaseOfflineScannerDisk())->put('review-projection-neighbor/retain.bin', 'invented-neighbor-review-private-bytes');
        $f['import'] = $this->reviewBasePreviewResults($f, $this->reviewBasePayload($f), 'review-projection.wqrresults');
        $f['log'] = $f['import']->importedLogs()->orderBy('master_sequence_number')->firstOrFail();
        $f['state'] = $f['import']->finalGuestStates()->where('external_guest_id', (string) $f['guestOne']->id)->sole();
        $f['conflict'] = OfflineScannerImportConflict::query()->create([
            'offline_scanner_result_import_id' => $f['import']->id,
            'offline_scanner_imported_log_id' => $f['log']->id,
            'event_id' => $f['event']->id,
            'guest_id' => $f['guestOne']->id,
            'replacement_guest_id' => $f['guestTwo']->id,
            'conflict_type' => OfflineScannerImportConflict::TYPE_SERVER_USED_CHANGED,
            'conflict_reason' => 'Synthetic retained historical review context',
            'server_used' => 1,
            'server_remaining' => 1,
            'server_state' => ['guest_id' => $f['guestOne']->id, 'event_id' => $f['event']->id, 'name' => self::OWN_HISTORY,
                'allowed_entries' => 2, 'used_entries' => 1, 'remaining_entries' => 1, 'status' => Guest::STATUS_PARTIALLY_USED],
            'snapshot_state' => ['guest_id' => $f['guestOne']->id, 'external_guest_id' => (string) $f['guestOne']->id,
                'allowed_entries' => 2, 'used_entries' => 0, 'remaining_entries' => 2, 'record_version' => 'retained-export-version'],
            'offline_request' => ['source_device_id' => 'android-master-1', 'master_sequence_number' => 1,
                'source_gate_name' => 'Main Gate', 'added_entries' => 1, 'used_after' => 1, 'remaining_after' => 1],
            'expected_final_state' => ['final_used' => 1, 'final_remaining' => 1, 'last_master_sequence_number' => 1],
            'resolution_status' => OfflineScannerImportConflict::RESOLUTION_RESOLVED,
            'resolution_action' => OfflineScannerImportConflict::ACTION_MAP_TO_REPLACEMENT_GUEST,
            'resolved_by_user_id' => $f['admin']->id,
            'resolution_notes' => 'Synthetic retained resolution metadata',
            'resolved_at' => now(),
        ]);

        return $f;
    }

    private function readViews(array $f, callable $assertProjection, array $actions = ['show', 'logs', 'conflicts', 'detail']): void
    {
        $before = $this->reviewSnapshot();
        $this->actingAs($f['admin'])->withSession(['selected_event_id' => $f['event']->id]);
        foreach ($actions as $action) {
            $detail = $action === 'detail';
            $response = $this->get($detail
                ? route('admin.offline-scanner-conflicts.show', $f['conflict'])
                : route('admin.offline-scanner-results.'.$action, $f['import']))->assertOk();
            $this->assertSame($before, $this->reviewSnapshot(), 'HTTP review GETs must conserve all table rows and private file bytes.');
            if ($detail) {
                $projection = $response->viewData('conflict');
                $projectedState = null;
            } else {
                $review = $response->viewData('resultImport');
                $projection = $review->conflicts->firstWhere('id', $f['conflict']->id);
                $projectedState = $review->finalGuestStates->firstWhere('id', $f['state']->id);
                $this->assertNotNull($projectedState);
            }
            $this->assertNotNull($projection);
            $assertProjection($response, $projection, $projectedState, $detail);
            $this->assertFalse($projection->isDirty(), 'A projection must not retain dirty attributes for accidental persistence.');
        }
    }

    private function withModelHooks(array $hooks, callable $work): void
    {
        $dispatcher = Guest::getEventDispatcher();
        $isolated = clone $dispatcher;
        foreach ($hooks as $model => $hook) {
            $isolated->listen('eloquent.retrieved: '.$model, $hook);
        }
        Guest::setEventDispatcher($isolated);
        try {
            $work();
        } finally {
            Guest::setEventDispatcher($dispatcher);
        }
    }

    private function assertNullCurrentReferences(OfflineScannerImportConflict $projection, $state): void
    {
        foreach (['guest', 'replacementGuest'] as $relation) {
            $this->assertTrue($projection->relationLoaded($relation));
            $this->assertNull($projection->getRelation($relation));
        }
        $this->assertNull($projection->guest_id);
        $this->assertNull($projection->replacement_guest_id);
        if ($state) {
            $this->assertTrue($state->relationLoaded('guest'));
            $this->assertNull($state->getRelation('guest'));
            $this->assertNull($state->guest_id);
            $this->assertFalse($state->isDirty());
        }
    }

    private function assertOwnCurrentReferences(array $f, OfflineScannerImportConflict $projection, $state): void
    {
        $this->assertSame($f['guestOne']->id, $projection->guest_id);
        $this->assertSame($f['guestOne']->id, $projection->guest?->id);
        $this->assertSame($f['guestTwo']->id, $projection->replacement_guest_id);
        $this->assertSame($f['guestTwo']->id, $projection->replacementGuest?->id);
        if ($state) {
            $this->assertSame($f['guestOne']->id, $state->guest_id);
            $this->assertSame($f['guestOne']->id, $state->guest?->id);
        }
    }

    private function assertRedactedServer(OfflineScannerImportConflict $projection): void
    {
        $this->assertNull($projection->server_state);
        $this->assertNull($projection->server_used);
        $this->assertNull($projection->server_remaining);
    }

    private function assertPreservedHistoricalPayloads(OfflineScannerImportConflict $stored, OfflineScannerImportConflict $projection): void
    {
        foreach (['snapshot_state', 'offline_request', 'expected_final_state', 'resolution_status', 'resolution_action', 'resolution_notes'] as $attribute) {
            $this->assertSame($stored->getAttribute($attribute), $projection->getAttribute($attribute));
        }
    }

    private function assertServerHtml(TestResponse $response, bool $detail, bool $retained): void
    {
        if (! $detail) {
            $response->assertSee($retained ? 'Allowed 2; Used 1; Remaining 1' : 'Allowed n/a; Used n/a; Remaining n/a', false);

            return;
        }
        $this->assertSame(1, preg_match('/<h3[^>]*>Server state<\/h3>(.*?)<\/section>/s', $response->getContent(), $matches));
        if ($retained) {
            $this->assertStringContainsString('>2</dd>', $matches[1]);
            $this->assertStringContainsString('>1</dd>', $matches[1]);
        } else {
            $this->assertStringNotContainsString('>7</dd>', $matches[1]);
            $this->assertStringNotContainsString('>3</dd>', $matches[1]);
            $this->assertStringContainsString('>n/a</dd>', $matches[1]);
        }
    }

    private function reviewSnapshot(): array
    {
        $rows = [];
        foreach (DB::connection()->selectFromWriteConnection("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name") as $table) {
            $contents = DB::table($table->name)->useWritePdo()->get()->map(static fn ($row) => (array) $row)->all();
            usort($contents, static fn ($a, $b): int => strcmp(json_encode($a), json_encode($b)));
            $rows[$table->name] = $contents;
        }
        $files = Storage::disk($this->reviewBaseOfflineScannerDisk())->allFiles();
        sort($files);
        $rows['_files'] = [];
        foreach ($files as $file) {
            $rows['_files'][$file] = hash('sha256', Storage::disk($this->reviewBaseOfflineScannerDisk())->get($file));
        }

        return $rows;
    }

    private function reviewBasePreviewResults(array $fixture, array $payload, string $fileName): OfflineScannerResultImport
    {
        $contents = $this->reviewBaseEncryptedResultsFile($payload, $fixture);

        $this->actingAs($fixture['admin'])
            ->withSession(['selected_event_id' => $fixture['event']->id])
            ->post(route('admin.offline-scanner-results.preview'), [
                'offline_scanner_session_id' => $fixture['session']->id,
                'results_file' => UploadedFile::fake()->createWithContent($fileName, $contents),
            ])
            ->assertSessionHasNoErrors();

        return OfflineScannerResultImport::query()->latest('id')->firstOrFail();
    }

    private function reviewBaseOfflineScannerDisk(): string
    {
        return (string) config('offline_scanner.disk', 'offline_scanner');
    }

    private function reviewBaseFixture(): array
    {
        $event = Event::factory()->create([
            'event_name' => 'ON Wedding',
            'venue_name' => 'Ocean Hall',
            'event_date' => '2026-08-01',
        ]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $master = User::factory()->create([
            'role' => User::ROLE_SCANNER,
            'event_id' => $event->id,
            'gate_name' => 'Main Gate',
            'name' => 'Master Scanner',
        ]);
        $client = User::factory()->create([
            'role' => User::ROLE_SCANNER,
            'event_id' => $event->id,
            'gate_name' => 'Garden Gate',
            'name' => 'Client Scanner 2',
        ]);
        $guestOne = $this->reviewBaseGuest($event, 'Asha Results Guest', '+255700000201', Guest::PASS_DOUBLE, 2, '1111');
        $guestTwo = $this->reviewBaseGuest($event, 'Baraka Results Guest', '+255700000202', Guest::PASS_SINGLE, 1, '2222');
        $resultKey = random_bytes(32);

        $session = OfflineScannerSession::factory()->active()->create([
            'event_id' => $event->id,
            'created_by_user_id' => $admin->id,
            'master_scanner_user_id' => $master->id,
            'master_gate_name' => 'Main Gate',
            'package_created_at' => now()->subHours(2),
            'package_expires_at' => now()->addDay(),
            'result_file_encryption_secret_encrypted' => Crypt::encryptString(base64_encode($resultKey)),
        ]);
        $package = OfflineScannerPackage::factory()->create([
            'offline_scanner_session_id' => $session->id,
            'generated_by_user_id' => $admin->id,
        ]);
        $session->update([
            'package_export_id' => $package->id,
            'package_fingerprint' => $package->package_fingerprint,
        ]);

        foreach ([
            [$guestOne, hash('sha256', 'guest-base-'.$guestOne->id.'-1')],
            [$guestTwo, hash('sha256', 'guest-base-'.$guestTwo->id.'-2')],
        ] as [$guest, $recordVersion]) {
            OfflineScannerPackageGuestSnapshot::query()->create([
                'offline_scanner_session_id' => $session->id,
                'offline_scanner_package_id' => $package->id,
                'guest_id' => $guest->id,
                'external_guest_id' => (string) $guest->id,
                'record_version' => $recordVersion,
                'allowed_entries' => $guest->allowed_entries,
                'used_entries' => $guest->used_entries,
                'remaining_entries' => $guest->remainingEntries(),
            ]);
        }

        OfflineScannerSessionUser::factory()->master()->create([
            'offline_scanner_session_id' => $session->id,
            'scanner_user_id' => $master->id,
            'assigned_gate' => 'Main Gate',
        ]);
        OfflineScannerSessionUser::factory()->create([
            'offline_scanner_session_id' => $session->id,
            'scanner_user_id' => $client->id,
            'assigned_gate' => 'Garden Gate',
        ]);

        return [
            'event' => $event,
            'admin' => $admin,
            'master' => $master,
            'client' => $client,
            'guestOne' => $guestOne,
            'guestTwo' => $guestTwo,
            'session' => $session->fresh(['packageExport', 'sessionUsers']),
            'package' => $package,
            'resultKey' => $resultKey,
        ];
    }

    private function reviewBaseGuest(Event $event, string $name, string $phone, string $passType, int $allowedEntries, string $manualCode): Guest
    {
        $guest = Guest::query()->create([
            'event_id' => $event->id,
            'name' => $name,
            'phone_number' => $phone,
            'manual_code' => $manualCode,
            'pass_type' => $passType,
            'allowed_entries' => $allowedEntries,
            'used_entries' => 0,
        ]);

        QrCode::query()->create([
            'guest_id' => $guest->id,
            'qr_token' => Guest::makeSecureToken(),
            'is_active' => true,
            'generated_at' => now(),
        ]);

        return $guest->fresh('qrCode');
    }

    private function reviewBasePayload(array $fixture): array
    {
        $resultsExportId = (string) Str::uuid();
        $guestOne = $fixture['guestOne'];
        $guestTwo = $fixture['guestTwo'];

        return [
            'session' => [
                'session_uuid' => $fixture['session']->session_uuid,
                'package_export_id' => $fixture['package']->package_uuid,
                'results_export_id' => $resultsExportId,
                'event_id' => (int) $fixture['event']->id,
                'master_device_id' => 'android-master-1',
                'session_started_at' => now()->subHour()->toJSON(),
                'session_closed_at' => now()->subMinutes(5)->toJSON(),
                'final_master_sequence_number' => 3,
                'exported_at' => now()->toJSON(),
            ],
            'devices' => [
                [
                    'device_id' => 'android-master-1',
                    'device_name' => 'Master Pixel',
                    'device_role' => 'master',
                    'scanner_user_id' => $fixture['master']->id,
                    'scanner_user_name' => $fixture['master']->name,
                    'gate_name' => 'Main Gate',
                    'paired_at' => now()->subHour()->toJSON(),
                    'last_seen_at' => now()->toJSON(),
                    'admissions_count' => 1,
                ],
                [
                    'device_id' => 'android-client-2',
                    'device_name' => 'Client Pixel 2',
                    'device_role' => 'client',
                    'scanner_user_id' => $fixture['client']->id,
                    'scanner_user_name' => $fixture['client']->name,
                    'gate_name' => 'Garden Gate',
                    'paired_at' => now()->subHour()->toJSON(),
                    'last_seen_at' => now()->toJSON(),
                    'admissions_count' => 1,
                ],
            ],
            'admission_logs' => [
                $this->reviewBaseLog($guestOne, $fixture['master'], 'android-master-1', 'Main Gate', 'qr', 1, 1, 0, 2, 1, 1),
                $this->reviewBaseLog($guestTwo, $fixture['client'], 'android-client-2', 'Garden Gate', 'manual', 2, 1, 0, 1, 1, 0),
                $this->reviewBaseLog($guestOne, $fixture['master'], 'android-master-1', 'Main Gate', 'qr', 3, 0, 1, 1, 1, 1, OfflineScannerResultSchema::RESULT_INVALID_QR),
            ],
            'final_guest_states' => [
                [
                    'external_guest_id' => (string) $guestOne->id,
                    'final_used' => 1,
                    'final_remaining' => 1,
                    'final_record_version' => hash('sha256', 'guest-one-final'),
                    'last_master_sequence_number' => 1,
                ],
                [
                    'external_guest_id' => (string) $guestTwo->id,
                    'final_used' => 1,
                    'final_remaining' => 0,
                    'final_record_version' => hash('sha256', 'guest-two-final'),
                    'last_master_sequence_number' => 2,
                ],
            ],
            'metrics' => [
                'total_scans' => 3,
                'total_admitted_people' => 2,
                'total_invalid_scans' => 1,
                'total_manual_admissions' => 1,
                'total_qr_admissions' => 1,
            ],
        ];
    }

    private function reviewBaseLog(
        Guest $guest,
        User $scanner,
        string $deviceId,
        string $gate,
        string $method,
        int $sequence,
        int $added,
        int $usedBefore,
        int $remainingBefore,
        int $usedAfter,
        int $remainingAfter,
        string $resultCode = OfflineScannerResultSchema::RESULT_ADMITTED_SUCCESSFULLY,
    ): array {
        return [
            'log_uuid' => (string) Str::uuid(),
            'request_uuid' => (string) Str::uuid(),
            'master_sequence_number' => $sequence,
            'external_guest_id' => (string) $guest->id,
            'scanner_user_id' => $scanner->id,
            'source_device_id' => $deviceId,
            'source_gate_name' => $gate,
            'admission_method' => $method,
            'qr_payload_hash' => $method === 'qr' ? hash('sha256', 'qr-payload-'.$sequence) : null,
            'added_entries' => $added,
            'used_before' => $usedBefore,
            'remaining_before' => $remainingBefore,
            'used_after' => $usedAfter,
            'remaining_after' => $remainingAfter,
            'result_code' => $resultCode,
            'device_timestamp' => now()->subMinutes(20 - $sequence)->toJSON(),
            'master_timestamp' => now()->subMinutes(19 - $sequence)->toJSON(),
            'monotonic_sequence' => $sequence,
            'base_guest_record_version' => hash('sha256', 'guest-base-'.$guest->id.'-'.$sequence),
        ];
    }

    private function reviewBaseEncryptedResultsFile(array $payload, array $fixture): string
    {
        $json = app(CanonicalJson::class);
        $payloadHash = hash('sha256', $json->encode($payload));
        $iv = random_bytes(12);
        $tag = '';
        $binding = [
            'event_id' => (int) $fixture['event']->id,
            'format' => OfflineScannerResultSchema::FORMAT,
            'master_device_id' => 'android-master-1',
            'payload_hash' => $payloadHash,
            'result_schema_version' => OfflineScannerResultSchema::SCHEMA_VERSION,
            'results_export_id' => $payload['session']['results_export_id'],
            'session_uuid' => $fixture['session']->session_uuid,
            'source_package_export_id' => $fixture['package']->package_uuid,
        ];
        $ciphertext = openssl_encrypt(
            $json->encode($payload),
            'aes-256-gcm',
            $fixture['resultKey'],
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $json->encode($binding),
        );

        $this->assertNotFalse($ciphertext);

        return $json->encode([
            'format' => OfflineScannerResultSchema::FORMAT,
            'result_schema_version' => OfflineScannerResultSchema::SCHEMA_VERSION,
            'source_package_export_id' => $fixture['package']->package_uuid,
            'session_uuid' => $fixture['session']->session_uuid,
            'results_export_id' => $payload['session']['results_export_id'],
            'event_id' => (int) $fixture['event']->id,
            'master_device_id' => 'android-master-1',
            'encryption_algorithm' => OfflineScannerResultSchema::ENCRYPTION_ALGORITHM,
            'initialization_vector' => base64_encode($iv),
            'encrypted_payload' => base64_encode($ciphertext),
            'authentication_tag' => base64_encode($tag),
            'payload_hash' => $payloadHash,
        ]);
    }
}
