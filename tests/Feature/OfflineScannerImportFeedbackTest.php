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
use App\Services\OfflineScannerResults\OfflineScannerImportConflictResolver;
use App\Services\OfflineScannerResults\OfflineScannerResultSchema;
use App\Services\OfflineScannerResults\OfflineScannerResultsImportService;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use Mockery;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Service-call feedback only; controlled SQLite savepoints do not prove every engine/commit outcome. */
class OfflineScannerImportFeedbackTest extends TestCase
{
    use RefreshDatabase;

    private const DRIVER_MARKER = 'private-import-driver-marker';

    private const BINDING_MARKER = 'private-import-binding-marker';

    private const BUSINESS_MESSAGE = 'Review this synthetic offline record before continuing.';

    private const NOTES = 'Synthetic private feedback resolution notes.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        Storage::fake($this->feedbackOfflineScannerDisk());
    }

    #[DataProvider('sqlFaultCases')]
    public function test_real_sql_failures_hide_driver_details_and_restore_precommit_state(string $action, string $stage): void
    {
        $f = $this->actionFixture($action);
        $before = $this->feedbackSnapshot();
        $db = DB::connection();
        $this->assertSame(1, $db->transactionLevel());
        $this->assertTrue($db->getPdo()->inTransaction());
        $message = $db->getPdo()->quote(self::DRIVER_MARKER.'; '.self::BINDING_MARKER.'; '.$f['import']->encrypted_file_path);
        $sessionId = (int) $f['session']->id;
        if ($stage === 'write') {
            $table = $action === 'confirm' ? 'offline_scanner_result_imports' : 'offline_scanner_import_conflicts';
            $column = $action === 'confirm' ? 'status' : 'resolution_status';
            $id = (int) ($action === 'confirm' ? $f['import']->id : $f['conflict']->id);
            $when = 'OLD."id" = '.$id;
            if ($action === 'confirm') {
                $when .= ' AND NEW."status" = '.$db->getPdo()->quote(OfflineScannerResultImport::STATUS_IMPORTING);
            }
            $db->unprepared('CREATE TEMP TRIGGER "synthetic_import_feedback_write" BEFORE UPDATE OF "'.$column.'" ON "'.$table.'" WHEN '.$when.' BEGIN SELECT RAISE(ABORT, '.$message.'); END');
        } else {
            $auditAction = $action === 'confirm' ? OfflineScannerSessionAudit::ACTION_RESULTS_IMPORTED : OfflineScannerSessionAudit::ACTION_CONFLICT_RESOLVED;
            $db->unprepared('CREATE TEMP TRIGGER "synthetic_import_feedback_audit" BEFORE INSERT ON "offline_scanner_session_audits" WHEN NEW."offline_scanner_session_id" = '.$sessionId.' AND NEW."action" = '.$db->getPdo()->quote($auditAction).' BEGIN SELECT RAISE(ABORT, '.$message.'); END');
        }
        $queries = [];
        $attempts = [];
        $db->beforeExecuting(function (string $sql) use ($action, $stage, $db, &$queries, &$attempts): void {
            $matches = $stage === 'audit'
                ? preg_match('/\Ainsert into "offline_scanner_session_audits"/i', $sql) === 1
                : preg_match('/\Aupdate "'.($action === 'confirm' ? 'offline_scanner_result_imports' : 'offline_scanner_import_conflicts').'"/i', $sql) === 1;
            if ($matches) {
                $attempts[] = ['level' => $db->transactionLevel(), 'completed_dml' => count($this->feedbackDml($queries))];
            }
        });
        $response = $this->feedbackQueries($queries, fn () => $this->requestFeedbackAction($action, $f));

        $this->assertCount(1, $attempts);
        $this->assertSame(3, $attempts[0]['level']);
        if ($action === 'confirm' && $stage === 'write') {
            $this->assertSame(0, $attempts[0]['completed_dml']);
        } else {
            $this->assertGreaterThan(0, $attempts[0]['completed_dml'], 'The fault must occur after actual completed mutations, not before the service action.');
        }
        // Snapshot equality is checked before the feedback assertion so old-code red
        // failures demonstrate disclosure after genuine precommit savepoint rollback.
        $this->assertSame($before, $this->feedbackSnapshot());
        $this->assertSame(1, $db->transactionLevel());
        $this->assertTrue($db->getPdo()->inTransaction());
        $this->assertFeedback($response, $action, $f, $this->genericFeedback($action));
    }

    public static function sqlFaultCases(): array
    {
        return ['confirm first status UPDATE' => ['confirm', 'write'], 'confirm audit INSERT' => ['confirm', 'audit'],
            'resolve conflict UPDATE' => ['resolve', 'write'], 'resolve audit INSERT' => ['resolve', 'audit']];
    }

    #[DataProvider('typedDriverCases')]
    public function test_typed_driver_failures_receive_safe_feedback_without_effects(string $action, string $kind): void
    {
        $f = $this->actionFixture($action);
        $before = $this->feedbackSnapshot();
        $exception = $kind === 'deadlock'
            ? new DeadlockException(self::DRIVER_MARKER.'; '.self::BINDING_MARKER)
            : new PDOException(self::DRIVER_MARKER.'; '.self::BINDING_MARKER);
        $this->replaceActionWithException($action, $exception);
        $queries = [];
        $response = $this->feedbackQueries($queries, fn () => $this->requestFeedbackAction($action, $f));

        $this->assertSame([], $this->feedbackDml($queries));
        $this->assertSame($before, $this->feedbackSnapshot());
        $this->assertSame(1, DB::connection()->transactionLevel());
        $this->assertTrue(DB::connection()->getPdo()->inTransaction());
        // These typed faults only verify the controller boundary, not a real deadlock.
        $this->assertFeedback($response, $action, $f, $this->genericFeedback($action));
    }

    public static function typedDriverCases(): array
    {
        return ['confirm PDOException' => ['confirm', 'pdo'], 'confirm DeadlockException' => ['confirm', 'deadlock'],
            'resolve PDOException' => ['resolve', 'pdo'], 'resolve DeadlockException' => ['resolve', 'deadlock']];
    }

    #[DataProvider('actions')]
    public function test_business_refusal_messages_remain_compatible_without_effects(string $action): void
    {
        $f = $this->actionFixture($action);
        $before = $this->feedbackSnapshot();
        $this->replaceActionWithException($action, new InvalidArgumentException(self::BUSINESS_MESSAGE));
        $queries = [];
        $response = $this->feedbackQueries($queries, fn () => $this->requestFeedbackAction($action, $f));

        $this->assertSame([], $this->feedbackDml($queries));
        $this->assertSame($before, $this->feedbackSnapshot());
        $this->assertSame(1, DB::connection()->transactionLevel());
        $this->assertTrue(DB::connection()->getPdo()->inTransaction());
        $this->assertFeedback($response, $action, $f, self::BUSINESS_MESSAGE);
    }

    public static function actions(): array
    {
        return ['confirm' => ['confirm'], 'resolve' => ['resolve']];
    }

    private function actionFixture(string $action): array
    {
        $f = $this->feedbackFixture();
        $payload = $this->feedbackPayload($f);
        if ($action === 'resolve') {
            $f['guestOne']->update(['used_entries' => 1]);
        }
        $f['import'] = $this->feedbackPreviewResults($f, $payload, 'synthetic-feedback.wqrresults');
        $this->assertSame(OfflineScannerResultImport::STATUS_PREVIEW_READY, $f['import']->status);
        if ($action === 'resolve') {
            $this->actingAs($f['admin'])->withSession(['selected_event_id' => $f['event']->id])
                ->post(route('admin.offline-scanner-results.confirm', $f['import']), ['confirm_import' => '1'])
                ->assertRedirect(route('admin.offline-scanner-sessions.show', $f['session']))->assertSessionHasNoErrors();
            $f['import'] = $f['import']->fresh();
            $this->assertSame(OfflineScannerResultImport::STATUS_COMPLETED_WITH_CONFLICTS, $f['import']->status);
            $f['conflict'] = $f['import']->conflicts()->where('conflict_type', OfflineScannerImportConflict::TYPE_SERVER_USED_CHANGED)->firstOrFail();
            $this->assertSame(OfflineScannerImportConflict::RESOLUTION_OPEN, $f['conflict']->resolution_status);
        }
        $neighborEvent = Event::factory()->create(['event_name' => 'Synthetic retained feedback neighbor']);
        $this->feedbackGuest($neighborEvent, 'Synthetic retained neighbor Guest', '+255700000299', Guest::PASS_SINGLE, 1, '9999');
        Storage::disk($this->feedbackOfflineScannerDisk())->put('feedback-neighbor/retain.bin', 'invented-retained-neighbor-file-bytes');
        $this->actingAs($f['admin'])->withSession(['selected_event_id' => $f['event']->id, 'guest_import' => ['marker' => 'preserved']]);
        session()->forget('success');

        return $f;
    }

    private function replaceActionWithException(string $action, \Throwable $exception): void
    {
        $class = $action === 'confirm' ? OfflineScannerResultsImportService::class : OfflineScannerImportConflictResolver::class;
        $method = $action === 'confirm' ? 'importVerified' : 'resolve';
        $mock = Mockery::mock($class);
        $mock->shouldReceive($method)->once()->andThrow($exception);
        $this->app->instance($class, $mock);
    }

    private function requestFeedbackAction(string $action, array $f): TestResponse
    {
        $back = $action === 'confirm' ? route('admin.offline-scanner-results.show', $f['import']) : route('admin.offline-scanner-conflicts.show', $f['conflict']);
        if ($action === 'confirm') {
            return $this->from($back)->post(route('admin.offline-scanner-results.confirm', $f['import']), ['confirm_import' => '1']);
        }

        return $this->from($back)->post(route('admin.offline-scanner-conflicts.resolve', $f['conflict']), [
            'resolution_action' => OfflineScannerImportConflict::ACTION_ACCEPT_OFFLINE_ADMISSION,
            'resolution_notes' => self::NOTES,
            'confirm_resolution' => '1',
        ]);
    }

    private function genericFeedback(string $action): string
    {
        return $action === 'confirm' ? 'Unable to import the verified Android scanner results.' : 'Unable to resolve the offline result conflict.';
    }

    private function assertFeedback(TestResponse $response, string $action, array $f, string $message): void
    {
        $key = $action === 'confirm' ? 'offline_results' : 'conflict_resolution';
        $back = $action === 'confirm' ? route('admin.offline-scanner-results.show', $f['import']) : route('admin.offline-scanner-conflicts.show', $f['conflict']);
        $response->assertRedirect($back)->assertSessionHasErrors([$key => $message])
            ->assertSessionMissing('success')->assertSessionHas('selected_event_id', $f['event']->id)
            ->assertSessionHas('guest_import.marker', 'preserved');
        $errors = session('errors')->getBag('default')->getMessages();
        $this->assertSame([$key => [$message]], $errors);
        $feedback = json_encode($errors, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        foreach ([self::DRIVER_MARKER, self::BINDING_MARKER, self::NOTES, $f['import']->encrypted_file_path,
            'SQL:', 'offline_scanner_result_imports', 'offline_scanner_import_conflicts', 'offline_scanner_session_audits'] as $private) {
            $this->assertStringNotContainsString($private, $feedback);
        }
    }

    private function feedbackSnapshot(): array
    {
        $rows = [];
        foreach (DB::connection()->selectFromWriteConnection("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name") as $table) {
            $contents = DB::table($table->name)->useWritePdo()->get()->map(static fn ($row) => (array) $row)->all();
            usort($contents, static fn ($a, $b): int => strcmp(json_encode($a), json_encode($b)));
            $rows[$table->name] = $contents;
        }
        $files = Storage::disk($this->feedbackOfflineScannerDisk())->allFiles();
        sort($files);
        $rows['_files'] = [];
        foreach ($files as $file) {
            $rows['_files'][$file] = hash('sha256', Storage::disk($this->feedbackOfflineScannerDisk())->get($file));
        }

        return $rows;
    }

    private function feedbackQueries(array &$queries, callable $callback): mixed
    {
        $db = DB::connection();
        $old = $db->getEventDispatcher();
        $dispatcher = new Dispatcher($this->app);
        $dispatcher->listen(QueryExecuted::class, static function (QueryExecuted $event) use (&$queries): void {
            $queries[] = $event->sql;
        });
        $db->setEventDispatcher($dispatcher);
        try {
            return $callback();
        } finally {
            $db->setEventDispatcher($old);
        }
    }

    private function feedbackDml(array $queries): array
    {
        return array_values(array_filter($queries, static fn ($sql): bool => preg_match('/\A\s*(insert|update|delete)\b/i', $sql)));
    }

    private function feedbackPreviewResults(array $fixture, array $payload, string $fileName): OfflineScannerResultImport
    {
        $contents = $this->feedbackEncryptedResultsFile($payload, $fixture);

        $this->actingAs($fixture['admin'])
            ->withSession(['selected_event_id' => $fixture['event']->id])
            ->post(route('admin.offline-scanner-results.preview'), [
                'offline_scanner_session_id' => $fixture['session']->id,
                'results_file' => UploadedFile::fake()->createWithContent($fileName, $contents),
            ])
            ->assertSessionHasNoErrors();

        return OfflineScannerResultImport::query()->latest('id')->firstOrFail();
    }

    private function feedbackOfflineScannerDisk(): string
    {
        return (string) config('offline_scanner.disk', 'offline_scanner');
    }

    private function feedbackFixture(): array
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
        $guestOne = $this->feedbackGuest($event, 'Asha Results Guest', '+255700000201', Guest::PASS_DOUBLE, 2, '1111');
        $guestTwo = $this->feedbackGuest($event, 'Baraka Results Guest', '+255700000202', Guest::PASS_SINGLE, 1, '2222');
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

    private function feedbackGuest(Event $event, string $name, string $phone, string $passType, int $allowedEntries, string $manualCode): Guest
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

    private function feedbackPayload(array $fixture): array
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
                $this->feedbackLog($guestOne, $fixture['master'], 'android-master-1', 'Main Gate', 'qr', 1, 1, 0, 2, 1, 1),
                $this->feedbackLog($guestTwo, $fixture['client'], 'android-client-2', 'Garden Gate', 'manual', 2, 1, 0, 1, 1, 0),
                $this->feedbackLog($guestOne, $fixture['master'], 'android-master-1', 'Main Gate', 'qr', 3, 0, 1, 1, 1, 1, OfflineScannerResultSchema::RESULT_INVALID_QR),
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

    private function feedbackLog(
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

    private function feedbackEncryptedResultsFile(array $payload, array $fixture): string
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
