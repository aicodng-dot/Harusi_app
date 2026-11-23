<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Guest;
use App\Models\OfflineScannerImportConflict;
use App\Models\OfflineScannerPackage;
use App\Models\OfflineScannerPackageGuestSnapshot;
use App\Models\OfflineScannerResultImport;
use App\Models\OfflineScannerSession;
use App\Models\OfflineScannerSessionAudit;
use App\Models\OfflineScannerSessionUser;
use App\Models\QrCode;
use App\Models\User;
use App\Services\CanonicalJson;
use App\Services\OfflineScannerResults\OfflineScannerResultSchema;
use App\Support\PositiveIntegerId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** New preview rows/review lookup only; historical/scanner references and authority fencing remain separate. */
class OfflineScannerPreviewGuestBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private const PRIVATE_NAME = 'PRIVATE-CURRENT-SERVER-GUEST-7200';

    private const OWN_NAME = 'Current Event Maximum Guest Control';

    private const PUBLIC_LABEL = 'Public authenticated Android payload label';

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        Storage::fake($this->boundaryOfflineScannerDisk());
    }

    #[DataProvider('positiveIdInputs')]
    public function test_positive_id_parser_rejects_ambiguous_or_out_of_range_values(mixed $value, ?int $expected): void
    {
        $this->assertSame($expected, PositiveIntegerId::parse($value));
    }

    public static function positiveIdInputs(): array
    {
        return [
            'positive integer' => [1, 1],
            'decimal string' => ['42', 42],
            'maximum integer' => [PHP_INT_MAX, PHP_INT_MAX],
            'maximum decimal string' => [(string) PHP_INT_MAX, PHP_INT_MAX],
            'zero integer' => [0, null],
            'zero string' => ['0', null],
            'negative' => [-1, null],
            'leading zero' => ['01', null],
            'leading space' => [' 1', null],
            'trailing space' => ['1 ', null],
            'trailing newline' => ["1\n", null],
            'trailing CRLF' => ["1\r\n", null],
            'embedded newline' => ["1\n2", null],
            'explicit plus' => ['+1', null],
            'decimal' => ['1.0', null],
            'exponent' => ['1e2', null],
            'float' => [1.0, null],
            'boolean true' => [true, null],
            'boolean false' => [false, null],
            'null' => [null, null],
            'array' => [[1], null],
            'empty' => ['', null],
            'unsigned overflow' => ['9223372036854775808', null],
            'long overflow' => [(string) PHP_INT_MAX.'0', null],
        ];
    }

    #[DataProvider('invalidLockedEventIds')]
    public function test_invalid_locked_session_event_is_rejected_before_preview_rows_or_file(string $kind): void
    {
        $f = $this->boundaryFixture();
        $this->retainedNeighbor($f);
        $contents = $this->boundaryEncryptedResultsFile($this->boundaryPayload($f), $f);
        $before = $this->boundarySnapshot();
        $dispatcher = OfflineScannerSession::getEventDispatcher();
        $isolatedDispatcher = clone $dispatcher;
        $observedLockedRow = false;
        $isolatedDispatcher->listen('eloquent.retrieved: '.OfflineScannerSession::class, function (OfflineScannerSession $locked) use ($f, $kind, &$observedLockedRow): void {
            if (DB::connection()->transactionLevel() !== 2 || $locked->getKey() !== $f['session']->getKey()
                || ! OfflineScannerSessionAudit::query()->where('offline_scanner_session_id', $locked->getKey())
                    ->where('action', OfflineScannerSessionAudit::ACTION_RESULTS_UPLOADED)->exists()) {
                return;
            }

            $attributes = $locked->getAttributes();
            $attributes['event_id'] = match ($kind) {
                'newline' => (string) $f['event']->id."\n",
                'padded' => '0'.$f['event']->id,
                default => '9223372036854775808',
            };
            $locked->setRawAttributes($attributes, true);
            $observedLockedRow = true;
        });
        OfflineScannerSession::setEventDispatcher($isolatedDispatcher);

        try {
            $this->actingAs($f['admin'])
                ->withSession(['selected_event_id' => $f['event']->id])
                ->post(route('admin.offline-scanner-results.preview'), [
                    'offline_scanner_session_id' => $f['session']->id,
                    'results_file' => UploadedFile::fake()->createWithContent('invalid-locked-event.wqrresults', $contents),
                ])
                ->assertRedirect()
                ->assertSessionHasErrors(['offline_results' => 'This offline scanner session has an invalid event reference.']);
        } finally {
            OfflineScannerSession::setEventDispatcher($dispatcher);
        }

        $this->assertTrue($observedLockedRow, 'Only the locked preview hydration is deliberately changed; the actual stored Event remains intact.');
        $this->assertSame(1, DB::connection()->transactionLevel());
        $after = $this->boundarySnapshot();
        $oldAuditIds = array_column($before['offline_scanner_session_audits'], 'id');
        $newAudits = array_values(array_filter($after['offline_scanner_session_audits'], static fn ($row): bool => ! in_array($row['id'], $oldAuditIds, true)));
        $this->assertCount(1, $newAudits);
        $this->assertSame(OfflineScannerSessionAudit::ACTION_RESULTS_UPLOADED, $newAudits[0]['action']);
        $before['offline_scanner_session_audits'] = $after['offline_scanner_session_audits'];
        $this->assertSame($before, $after, 'The existing preliminary upload audit is the only permitted effect; no preview rows or file are created.');
    }

    public static function invalidLockedEventIds(): array
    {
        return ['newline' => ['newline'], 'padded' => ['padded'], 'overflow' => ['overflow']];
    }

    #[DataProvider('foreignOrAliasedIds')]
    public function test_preview_never_copies_foreign_or_aliased_current_guest_context(string $kind): void
    {
        $f = $this->boundaryFixture();
        $this->retainedNeighbor($f);
        if ($kind === 'foreign_unowned') {
            $event = Event::factory()->create(['event_name' => 'Synthetic other unowned boundary Event']);
            $victim = $this->boundaryVictim($event, self::PRIVATE_NAME, '9031', '+255700003031');
            $externalId = (string) $victim->id;
        } elseif ($kind === 'foreign_owned') {
            $victim = $f['neighborGuest'];
            $externalId = (string) $victim->id;
        } else {
            // Same-Event MAX row makes an Event predicate alone insufficient.
            $victim = $this->boundaryVictim($f['event'], self::PRIVATE_NAME, '9032', '+255700003032', PHP_INT_MAX);
            $externalId = $kind === 'overflow' ? '9223372036854775808' : (string) PHP_INT_MAX."\n";
        }
        $payload = $this->boundaryPayload($f);
        $this->replacePayloadGuest($payload, (string) $f['guestOne']->id, $externalId);
        $this->publicPayloadLabel($payload);
        $this->assertStringNotContainsString(self::PRIVATE_NAME, app(CanonicalJson::class)->encode($payload));
        $before = $this->boundarySnapshot();
        [$import, $contents] = $this->createBoundaryPreview($f, $payload);
        $this->assertPreviewEffectsConfined($before, $import, $contents, 2);
        $this->assertHiddenCurrentContext($import, $externalId, 2);
        $this->assertSame([
            OfflineScannerImportConflict::TYPE_GUEST_NOT_FOUND,
        ], $import->conflicts()->whereHas('importedLog', fn ($q) => $q->where('external_guest_id', $externalId))
            ->pluck('conflict_type')->unique()->values()->all());
        $afterPreview = $this->boundarySnapshot();
        foreach (['show', 'logs', 'conflicts'] as $action) {
            $response = $this->get(route('admin.offline-scanner-results.'.$action, $import))->assertOk();
            $response->assertDontSee(self::PRIVATE_NAME);
            foreach ($this->renderedLogRows($response, $externalId) as $row) {
                $this->assertStringContainsString('Unknown guest', $row);
            }
            $this->assertCount(2, $this->renderedLogRows($response, $externalId));
            $this->assertReviewHasNoCurrentContext($response, $externalId);
        }
        $this->assertSame($afterPreview, $this->boundarySnapshot(), 'Review GETs must not change rows or private fixture files.');
    }

    public static function foreignOrAliasedIds(): array
    {
        return ['other unowned Event' => ['foreign_unowned'], 'owned tenant Event' => ['foreign_owned'],
            'overflow aliases real same-Event maximum' => ['overflow'], 'newline aliases real same-Event maximum' => ['newline']];
    }

    public function test_moved_exported_guest_keeps_wrong_event_classification_without_current_private_context(): void
    {
        $f = $this->boundaryFixture();
        $this->retainedNeighbor($f);
        $payload = $this->boundaryPayload($f);
        $this->publicPayloadLabel($payload);
        Guest::query()->whereKey($f['guestOne']->id)->update([
            'event_id' => $f['neighborEvent']->id, 'name' => self::PRIVATE_NAME,
            'pass_type' => Guest::PASS_SPECIAL, 'allowed_entries' => 10, 'used_entries' => 7,
            'status' => Guest::STATUS_PARTIALLY_USED,
        ]);
        $this->assertStringNotContainsString(self::PRIVATE_NAME, app(CanonicalJson::class)->encode($payload));
        $before = $this->boundarySnapshot();
        [$import, $contents] = $this->createBoundaryPreview($f, $payload);
        $this->assertPreviewEffectsConfined($before, $import, $contents, 2);
        $externalId = (string) $f['guestOne']->id;
        $this->assertHiddenCurrentContext($import, $externalId, 2);
        $conflicts = $import->conflicts()->whereHas('importedLog', fn ($q) => $q->where('external_guest_id', $externalId))->get();
        foreach ($conflicts as $conflict) {
            $this->assertSame(OfflineScannerImportConflict::TYPE_WRONG_EVENT, $conflict->conflict_type);
            // Exported historical snapshot is retained; current Guest FK/state are not.
            $this->assertSame($externalId, (string) $conflict->snapshot_state['guest_id']);
            $this->assertSame(2, (int) $conflict->snapshot_state['allowed_entries']);
            $this->assertSame(0, (int) $conflict->snapshot_state['used_entries']);
            $this->get(route('admin.offline-scanner-conflicts.show', $conflict))->assertOk()->assertDontSee(self::PRIVATE_NAME);
        }
        $response = $this->get(route('admin.offline-scanner-results.show', $import))->assertOk()->assertDontSee(self::PRIVATE_NAME);
        $this->assertReviewHasNoCurrentContext($response, $externalId);
    }

    public function test_canonical_same_event_maximum_without_snapshot_keeps_own_guest_context(): void
    {
        $f = $this->boundaryFixture();
        $this->retainedNeighbor($f);
        $victim = $this->boundaryVictim($f['event'], self::OWN_NAME, '9033', '+255700003033', PHP_INT_MAX);
        $externalId = (string) PHP_INT_MAX;
        $payload = $this->boundaryPayload($f);
        $this->replacePayloadGuest($payload, (string) $f['guestOne']->id, $externalId);
        $this->publicPayloadLabel($payload);
        $before = $this->boundarySnapshot();
        [$import, $contents] = $this->createBoundaryPreview($f, $payload);
        $this->assertPreviewEffectsConfined($before, $import, $contents, 2);
        $conflicts = $import->conflicts()->whereHas('importedLog', fn ($q) => $q->where('external_guest_id', $externalId))->get();
        $this->assertCount(2, $conflicts);
        foreach ($conflicts as $conflict) {
            $this->assertSame(OfflineScannerImportConflict::TYPE_GUEST_NOT_FOUND, $conflict->conflict_type);
            $this->assertSame($externalId, (string) $conflict->getRawOriginal('guest_id'));
            $this->assertSame(self::OWN_NAME, $conflict->server_state['name']);
            $this->assertSame(7, (int) $conflict->server_used);
            $this->assertSame(3, (int) $conflict->server_remaining);
        }
        $state = $import->finalGuestStates()->where('external_guest_id', $externalId)->sole();
        $this->assertSame($externalId, (string) $state->getRawOriginal('guest_id'));
        $response = $this->get(route('admin.offline-scanner-results.show', $import))->assertOk()->assertSee(self::OWN_NAME);
        $rows = $this->renderedLogRows($response, $externalId);
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertStringContainsString(self::OWN_NAME, $row);
        }
    }

    #[DataProvider('mixedAliases')]
    public function test_mixed_maximum_and_alias_logs_resolve_current_guest_only_for_the_canonical_row(string $kind): void
    {
        [$f, $payload, $alias] = $this->mixedMaximumFixture($kind);
        $before = $this->boundarySnapshot();
        [$import, $contents] = $this->createBoundaryPreview($f, $payload);
        $this->assertPreviewEffectsConfined($before, $import, $contents, 3);
        $this->assertHiddenCurrentContext($import, $alias, 2);
        $canonical = (string) PHP_INT_MAX;
        $canonicalConflict = $import->conflicts()->whereHas('importedLog', fn ($q) => $q->where('external_guest_id', $canonical))->sole();
        $this->assertSame($canonical, (string) $canonicalConflict->getRawOriginal('guest_id'));
        $this->assertSame(self::OWN_NAME, $canonicalConflict->server_state['name']);
        $this->assertSame($canonical, (string) $import->finalGuestStates()->where('external_guest_id', $canonical)->sole()->getRawOriginal('guest_id'));
        $afterPreview = $this->boundarySnapshot();
        foreach (['show', 'logs', 'conflicts'] as $action) {
            $response = $this->get(route('admin.offline-scanner-results.'.$action, $import))->assertOk();
            $aliasRows = $this->renderedLogRows($response, $alias);
            $this->assertCount(2, $aliasRows);
            foreach ($aliasRows as $row) {
                $this->assertStringContainsString('Unknown guest', $row);
                $this->assertStringNotContainsString(self::OWN_NAME, $row);
            }
            $canonicalRows = $this->renderedLogRows($response, $canonical);
            $this->assertCount(1, $canonicalRows);
            $this->assertStringContainsString(self::OWN_NAME, $canonicalRows[0]);
            $this->assertReviewHasNoCurrentContext($response, $alias);
        }
        $this->assertSame($afterPreview, $this->boundarySnapshot());
    }

    public static function mixedAliases(): array
    {
        return ['overflow with canonical maximum' => ['overflow'], 'newline with canonical maximum' => ['newline']];
    }

    public function test_numeric_guest_search_does_not_promote_overflow_into_the_real_maximum_guest(): void
    {
        [$f, $payload, $alias] = $this->mixedMaximumFixture('overflow');
        [$import] = $this->createBoundaryPreview($f, $payload);
        $afterPreview = $this->boundarySnapshot();
        $response = $this->get(route('admin.offline-scanner-results.show', ['resultImport' => $import, 'guest' => $alias]))->assertOk();
        $response->assertViewHas('admissionLogs', function ($logs) use ($alias): bool {
            $this->assertSame([$alias, $alias], $logs->getCollection()->pluck('external_guest_id')->all());

            return true;
        });
        $this->assertCount(2, $this->renderedLogRows($response, $alias));
        $this->assertCount(0, $this->renderedLogRows($response, (string) PHP_INT_MAX));
        $response->assertDontSee(self::OWN_NAME);
        $canonical = $this->get(route('admin.offline-scanner-results.show', ['resultImport' => $import, 'guest' => (string) PHP_INT_MAX]))->assertOk();
        $this->assertCount(1, $this->renderedLogRows($canonical, (string) PHP_INT_MAX));
        $canonical->assertSee(self::OWN_NAME);
        $this->assertSame($afterPreview, $this->boundarySnapshot());
    }

    private function mixedMaximumFixture(string $kind): array
    {
        $f = $this->boundaryFixture();
        $this->retainedNeighbor($f);
        $this->boundaryVictim($f['event'], self::OWN_NAME, '9034', '+255700003034', PHP_INT_MAX);
        $alias = $kind === 'overflow' ? '9223372036854775808' : (string) PHP_INT_MAX."\n";
        $payload = $this->boundaryPayload($f);
        $this->replacePayloadGuest($payload, (string) $f['guestOne']->id, $alias);
        $this->replacePayloadGuest($payload, (string) $f['guestTwo']->id, (string) PHP_INT_MAX);
        $this->publicPayloadLabel($payload);
        $this->assertStringNotContainsString(self::OWN_NAME, app(CanonicalJson::class)->encode($payload));

        return [$f, $payload, $alias];
    }

    private function retainedNeighbor(array &$f): void
    {
        DB::table('workspaces')->insert(['id' => 101, 'name' => 'Synthetic retained P20 workspace']);
        $f['neighborEvent'] = Event::factory()->create(['workspace_id' => 101, 'event_name' => 'Synthetic owned P20 neighbor Event']);
        $f['neighborGuest'] = $this->boundaryVictim($f['neighborEvent'], self::PRIVATE_NAME, '9030', '+255700003030');
        Storage::disk($this->boundaryOfflineScannerDisk())->put('preview-guest-neighbor/retain.bin', 'invented-retained-neighbor-private-bytes');
    }

    private function boundaryVictim(Event $event, string $name, string $code, string $phone, ?int $id = null): Guest
    {
        $attributes = ['event_id' => $event->id, 'name' => $name, 'phone_number' => $phone,
            'manual_code' => $code, 'pass_type' => Guest::PASS_SPECIAL, 'allowed_entries' => 10,
            'used_entries' => 7, 'status' => Guest::STATUS_PARTIALLY_USED];
        if ($id !== null) {
            $attributes['id'] = $id;
        }
        $guest = Guest::query()->forceCreate($attributes);
        QrCode::query()->create(['guest_id' => $guest->id, 'qr_token' => Guest::makeSecureToken(), 'is_active' => true, 'generated_at' => now()]);

        return $guest->fresh('qrCode');
    }

    private function replacePayloadGuest(array &$payload, string $old, string $replacement): void
    {
        foreach ($payload['admission_logs'] as &$log) {
            if ($log['external_guest_id'] === $old) {
                $log['external_guest_id'] = $replacement;
            }
        }
        unset($log);
        foreach ($payload['final_guest_states'] as &$state) {
            if ($state['external_guest_id'] === $old) {
                $state['external_guest_id'] = $replacement;
            }
        }
        unset($state);
    }

    private function publicPayloadLabel(array &$payload): void
    {
        $payload['devices'][0]['device_name'] = self::PUBLIC_LABEL;
    }

    private function createBoundaryPreview(array $f, array $payload): array
    {
        $contents = $this->boundaryEncryptedResultsFile($payload, $f);
        $this->actingAs($f['admin'])->withSession(['selected_event_id' => $f['event']->id, 'guest_import' => ['marker' => 'preserved']]);
        $response = $this->post(route('admin.offline-scanner-results.preview'), [
            'offline_scanner_session_id' => $f['session']->id,
            'results_file' => UploadedFile::fake()->createWithContent('preview-guest-boundary.wqrresults', $contents),
        ])->assertSessionHasNoErrors();
        $import = OfflineScannerResultImport::query()->latest('id')->sole();
        $response->assertRedirect(route('admin.offline-scanner-results.show', $import));
        $this->assertSame(OfflineScannerResultImport::STATUS_PREVIEW_READY, $import->status);
        $this->assertSame(1, DB::connection()->transactionLevel());

        return [$import, $contents];
    }

    private function assertHiddenCurrentContext(OfflineScannerResultImport $import, string $externalId, int $count): void
    {
        $conflicts = $import->conflicts()->whereHas('importedLog', fn ($q) => $q->where('external_guest_id', $externalId))->get();
        $this->assertCount($count, $conflicts);
        foreach ($conflicts as $conflict) {
            $this->assertNull($conflict->getRawOriginal('guest_id'), 'A foreign or aliased current Guest must not become the conflict FK.');
            $this->assertNull($conflict->server_state, 'Private current server state must not be copied into the selected import.');
            $this->assertNull($conflict->getRawOriginal('server_used'));
            $this->assertNull($conflict->getRawOriginal('server_remaining'));
        }
        $states = $import->finalGuestStates()->where('external_guest_id', $externalId)->get();
        $this->assertCount(1, $states);
        $this->assertNull($states->sole()->getRawOriginal('guest_id'), 'Final state must not retain the foreign/aliased current Guest FK.');
    }

    private function assertReviewHasNoCurrentContext(TestResponse $response, string $externalId): void
    {
        $response->assertViewHas('resultImport', function ($review) use ($externalId): bool {
            foreach ($review->conflicts as $conflict) {
                if ($conflict->importedLog?->external_guest_id === $externalId) {
                    $this->assertNull($conflict->getRawOriginal('guest_id'));
                    $this->assertNull($conflict->server_state);
                    $this->assertNull($conflict->guest);
                }
            }
            foreach ($review->finalGuestStates as $state) {
                if ($state->external_guest_id === $externalId) {
                    $this->assertNull($state->getRawOriginal('guest_id'));
                    $this->assertNull($state->guest);
                }
            }

            return true;
        });
    }

    private function renderedLogRows(TestResponse $response, string $externalId): array
    {
        preg_match_all('/<tr\b[^>]*>(.*?)<\/tr>/si', $response->getContent(), $rows);
        $needle = 'External '.e($externalId).'</p>';

        return array_values(array_filter($rows[1], static fn (string $row): bool => str_contains($row, $needle)));
    }

    private function assertPreviewEffectsConfined(array $before, OfflineScannerResultImport $import, string $contents, int $conflictCount): void
    {
        $after = $this->boundarySnapshot();
        $newCounts = ['offline_scanner_result_imports' => 1, 'offline_scanner_result_devices' => 2,
            'offline_scanner_imported_logs' => 3, 'offline_scanner_final_guest_states' => 2,
            'offline_scanner_import_conflicts' => $conflictCount, 'offline_scanner_session_audits' => 2];
        foreach ($newCounts as $table => $count) {
            $ids = array_column($before[$table], 'id');
            $retained = array_values(array_filter($after[$table], static fn ($row): bool => in_array($row['id'], $ids, true)));
            $added = array_values(array_filter($after[$table], static fn ($row): bool => ! in_array($row['id'], $ids, true)));
            $this->assertSame($before[$table], $retained, 'Existing '.$table.' rows must remain intact.');
            $this->assertCount($count, $added);
            foreach ($added as $row) {
                if ($table === 'offline_scanner_result_imports') {
                    $this->assertSame((string) $import->id, (string) $row['id']);
                } elseif (array_key_exists('offline_scanner_result_import_id', $row)) {
                    $this->assertSame((string) $import->id, (string) $row['offline_scanner_result_import_id']);
                }
                if (array_key_exists('offline_scanner_session_id', $row)) {
                    $this->assertSame((string) $import->offline_scanner_session_id, (string) $row['offline_scanner_session_id']);
                }
                if (array_key_exists('event_id', $row)) {
                    $this->assertSame((string) $import->event_id, (string) $row['event_id']);
                }
            }
            $before[$table] = $after[$table];
        }
        foreach ($before['offline_scanner_sessions'] as &$row) {
            if ((string) $row['id'] === (string) $import->offline_scanner_session_id) {
                $row['status'] = OfflineScannerSession::STATUS_RESULTS_UPLOADED;
            }
        }
        unset($row);
        $path = $import->encrypted_file_path;
        $this->assertIsString($path);
        $this->assertSame(hash('sha256', $contents), hash('sha256', Storage::disk($this->boundaryOfflineScannerDisk())->get($path)));
        $before['_files'][$path] = hash('sha256', $contents);
        ksort($before['_files']);
        $this->assertSame($before, $after, 'Only target preview rows, its Session status, two audits and its new private file may change.');
    }

    private function boundarySnapshot(): array
    {
        $rows = [];
        foreach (DB::connection()->selectFromWriteConnection("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name") as $table) {
            $contents = DB::table($table->name)->useWritePdo()->get()->map(static fn ($row) => (array) $row)->all();
            usort($contents, static fn ($a, $b): int => strcmp(json_encode($a), json_encode($b)));
            $rows[$table->name] = $contents;
        }
        $files = Storage::disk($this->boundaryOfflineScannerDisk())->allFiles();
        sort($files);
        $rows['_files'] = [];
        foreach ($files as $file) {
            $rows['_files'][$file] = hash('sha256', Storage::disk($this->boundaryOfflineScannerDisk())->get($file));
        }

        return $rows;
    }

    private function boundaryPreviewResults(array $fixture, array $payload, string $fileName): OfflineScannerResultImport
    {
        $contents = $this->boundaryEncryptedResultsFile($payload, $fixture);

        $this->actingAs($fixture['admin'])
            ->withSession(['selected_event_id' => $fixture['event']->id])
            ->post(route('admin.offline-scanner-results.preview'), [
                'offline_scanner_session_id' => $fixture['session']->id,
                'results_file' => UploadedFile::fake()->createWithContent($fileName, $contents),
            ])
            ->assertSessionHasNoErrors();

        return OfflineScannerResultImport::query()->latest('id')->firstOrFail();
    }

    private function boundaryOfflineScannerDisk(): string
    {
        return (string) config('offline_scanner.disk', 'offline_scanner');
    }

    private function boundaryFixture(): array
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
        $guestOne = $this->boundaryGuest($event, 'Asha Results Guest', '+255700000201', Guest::PASS_DOUBLE, 2, '1111');
        $guestTwo = $this->boundaryGuest($event, 'Baraka Results Guest', '+255700000202', Guest::PASS_SINGLE, 1, '2222');
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

    private function boundaryGuest(Event $event, string $name, string $phone, string $passType, int $allowedEntries, string $manualCode): Guest
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

    private function boundaryPayload(array $fixture): array
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
                $this->boundaryLog($guestOne, $fixture['master'], 'android-master-1', 'Main Gate', 'qr', 1, 1, 0, 2, 1, 1),
                $this->boundaryLog($guestTwo, $fixture['client'], 'android-client-2', 'Garden Gate', 'manual', 2, 1, 0, 1, 1, 0),
                $this->boundaryLog($guestOne, $fixture['master'], 'android-master-1', 'Main Gate', 'qr', 3, 0, 1, 1, 1, 1, OfflineScannerResultSchema::RESULT_INVALID_QR),
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

    private function boundaryLog(
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

    private function boundaryEncryptedResultsFile(array $payload, array $fixture): string
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
