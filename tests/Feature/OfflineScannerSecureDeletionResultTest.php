<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Guest;
use App\Models\OfflineScannerPackage;
use App\Models\OfflineScannerResultImport;
use App\Models\OfflineScannerSession;
use App\Models\OfflineScannerSessionAudit;
use App\Models\User;
use App\Services\OfflineScannerFileStorage;
use App\Services\OfflineScannerSessionAuditService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Delete-result checks only; no claim about row fencing or SQL-after-delete recovery. */
class OfflineScannerSecureDeletionResultTest extends TestCase
{
    use RefreshDatabase;

    private const REASON = 'Synthetic retention deletion reason.';

    #[DataProvider('kinds')]
    public function test_false_delete_of_existing_bytes_never_certifies_deletion(string $kind): void
    {
        $f = $this->fixture($kind, true);
        $before = $this->snapshot();
        $queries = [];
        $response = $this->queries($queries, fn () => $this->requestDeletion($kind));

        $this->assertSame([[$f['path'], $f['disk']]], $f['files']->deleteCalls);
        $this->assertSame([[$f['path'], $f['disk']]], $f['files']->existsCalls);
        // On old code this exposes persisted secure_deleted_* and file_deleted audit.
        $this->assertSame($before, $this->snapshot(), 'False deletion retained bytes but falsely changed deletion metadata, audits or other fixture state.');
        $this->assertSame([], $this->dml($queries));
        $response->assertRedirect(route('admin.offline-scanner-sessions.show', 401))
            ->assertSessionHasErrors([$this->errorKey($kind) => 'Unable to delete encrypted offline scanner file.'])
            ->assertSessionMissing('success')->assertSessionHas('selected_event_id', 301)
            ->assertSessionHas('guest_import.marker', 'preserved');
        $this->assertSame(1, DB::connection()->transactionLevel());
    }

    #[DataProvider('kinds')]
    public function test_successful_delete_keeps_existing_metadata_audit_and_history_compatibility(string $kind): void
    {
        $f = $this->fixture($kind, false);
        $before = $this->snapshot();
        $queries = [];
        $response = $this->queries($queries, fn () => $this->requestDeletion($kind));

        $this->assertSame([[$f['path'], $f['disk']]], $f['files']->deleteCalls);
        $this->assertSame([[$f['path'], $f['disk']]], $f['files']->existsCalls);
        $response->assertRedirect(route('admin.offline-scanner-sessions.show', 401))
            ->assertSessionHas('success', $this->successMessage($kind))->assertSessionHasNoErrors();
        $this->assertSuccessDelta($before, $kind, $f['path']);
        $this->assertCount(2, $this->dml($queries));
    }

    #[DataProvider('kinds')]
    public function test_already_missing_bytes_keep_existing_deletion_metadata_and_audit_behavior(string $kind): void
    {
        $f = $this->fixture($kind, true);
        Storage::disk($f['disk'])->delete($f['path']);
        $before = $this->snapshot();

        $this->requestDeletion($kind)->assertRedirect()
            ->assertSessionHas('success', $this->successMessage($kind))->assertSessionHasNoErrors();
        $this->assertSame([], $f['files']->deleteCalls);
        $this->assertSame([[$f['path'], $f['disk']]], $f['files']->existsCalls);
        $this->assertSuccessDelta($before, $kind);
    }

    public function test_results_without_a_path_keep_existing_metadata_only_deletion_behavior(): void
    {
        $f = $this->fixture('results', true);
        DB::table('offline_scanner_result_imports')->where('id', 601)->update([
            'encrypted_file_path' => null, 'encrypted_file_size' => null, 'storage_disk' => '',
        ]);
        $before = $this->snapshot();

        $this->requestDeletion('results')->assertRedirect()
            ->assertSessionHas('success', $this->successMessage('results'))->assertSessionHasNoErrors();
        $this->assertSame([], $f['files']->deleteCalls);
        $this->assertSame([], $f['files']->existsCalls);
        // The orphan fixture bytes are conserved: no path is inferred or guessed.
        $this->assertSuccessDelta($before, 'results');
    }

    #[DataProvider('kinds')]
    public function test_disabled_retention_policy_refuses_before_file_or_database_effects(string $kind): void
    {
        $f = $this->fixture($kind, false);
        config(['offline_scanner.retention.allow_secure_deletion' => false]);
        $before = $this->snapshot();
        $queries = [];

        $this->queries($queries, fn () => $this->requestDeletion($kind))->assertForbidden()->assertSessionMissing('success');
        $this->assertSame([], $f['files']->deleteCalls);
        $this->assertSame([], $f['files']->existsCalls);
        $this->assertSame([], $this->dml($queries));
        $this->assertSame($before, $this->snapshot());
    }

    public static function kinds(): array
    {
        return ['package' => ['package'], 'results' => ['results']];
    }

    private function fixture(string $kind, bool $returnFalse): array
    {
        $this->freezeTime();
        $disk = (string) config('offline_scanner.disk');
        Storage::fake($disk);
        config(['offline_scanner.retention.allow_secure_deletion' => true]);
        DB::table('workspaces')->insert(['id' => 101, 'name' => 'Synthetic deletion neighbor workspace']);
        Event::factory()->create(['id' => 301, 'workspace_id' => null, 'event_name' => 'Synthetic deletion Event']);
        Event::factory()->create(['id' => 302, 'workspace_id' => 101, 'event_name' => 'Synthetic owned neighbor Event']);
        $actor = User::factory()->create(['id' => 211, 'role' => User::ROLE_ADMIN, 'is_active' => true]);
        User::factory()->create(['id' => 215, 'role' => User::ROLE_ADMIN, 'is_active' => true]);
        Guest::query()->forceCreate(['id' => 901, 'event_id' => 302, 'name' => 'Synthetic retained neighbor Guest',
            'phone_number' => '+255712345678', 'manual_code' => '0901', 'pass_type' => Guest::PASS_DOUBLE,
            'allowed_entries' => 2, 'used_entries' => 1, 'status' => 'partially_used']);
        foreach ([401 => 301, 402 => 302] as $id => $eventId) {
            OfflineScannerSession::factory()->create(['id' => $id, 'event_id' => $eventId, 'created_by_user_id' => 215,
                'status' => OfflineScannerSession::STATUS_CLOSED, 'session_closed_at' => now()->subHour(),
                'results_imported_at' => now()->subHour(), 'package_expires_at' => now()->subDay()]);
        }
        foreach ([501 => 401, 502 => 402, 503 => 401] as $id => $parent) {
            $bytes = 'invented-private-package-deletion-bytes-'.$id;
            $package = OfflineScannerPackage::factory()->create(['id' => $id, 'offline_scanner_session_id' => $parent,
                'generated_by_user_id' => 215, 'file_name' => 'synthetic-'.$id.'.wqrpkg',
                'file_path' => 'offline-packages/synthetic/'.$id.'.wqrpkg', 'storage_disk' => $disk,
                'file_hash' => hash('sha256', $bytes), 'encrypted_file_size' => strlen($bytes),
                'revoked_at' => now()->subHours(2), 'archived_at' => now()->subHour(), 'archived_by_user_id' => 215]);
            Storage::disk($disk)->put($package->file_path, $bytes);
        }
        // Target package is historical, revoked and archived; none forbids secure deletion.
        DB::table('offline_scanner_sessions')->where('id', 401)->update(['package_export_id' => 503]);
        DB::table('offline_scanner_sessions')->where('id', 402)->update(['package_export_id' => 502]);
        foreach ([601 => [401, 301], 602 => [402, 302]] as $id => [$sessionId, $eventId]) {
            $bytes = 'invented-private-results-deletion-bytes-'.$id;
            $import = OfflineScannerResultImport::factory()->create(['id' => $id, 'offline_scanner_session_id' => $sessionId,
                'event_id' => $eventId, 'uploaded_by_user_id' => 215, 'status' => OfflineScannerResultImport::STATUS_FAILED,
                'encrypted_file_path' => 'offline-results/synthetic/'.$id.'.wqrresults', 'storage_disk' => $disk,
                'file_hash' => hash('sha256', $bytes), 'encrypted_file_size' => strlen($bytes),
                'archived_at' => now()->subHour(), 'archived_by_user_id' => 215]);
            Storage::disk($disk)->put($import->encrypted_file_path, $bytes);
        }
        OfflineScannerSessionAudit::query()->forceCreate(['id' => 902, 'offline_scanner_session_id' => 402,
            'event_id' => 302, 'actor_user_id' => 215, 'action' => OfflineScannerSessionAudit::ACTION_SESSION_CLOSED,
            'reason' => 'Synthetic neighbor retained history', 'metadata' => ['retain' => 'exact'], 'occurred_at' => now()->subHour()]);
        $resource = $kind === 'package' ? OfflineScannerPackage::query()->findOrFail(501) : OfflineScannerResultImport::query()->findOrFail(601);
        $path = $resource->getRawOriginal($kind === 'package' ? 'file_path' : 'encrypted_file_path');
        $files = new SecureDeletionResultFixtureStorage(app(OfflineScannerSessionAuditService::class), $returnFalse);
        $this->app->instance(OfflineScannerFileStorage::class, $files);
        $this->actingAs($actor)->withSession(['selected_event_id' => 301, 'guest_import' => ['marker' => 'preserved']]);

        return compact('actor', 'resource', 'files', 'disk', 'path');
    }

    private function requestDeletion(string $kind): TestResponse
    {
        return $this->from(route('admin.offline-scanner-sessions.show', 401))->delete(
            route($kind === 'package' ? 'admin.offline-scanner-packages.destroy' : 'admin.offline-scanner-results.destroy', $this->resourceId($kind)),
            ['deletion_reason' => '  '.self::REASON.'  ', 'confirm_secure_delete' => '1'],
        );
    }

    private function assertSuccessDelta(array $before, string $kind, ?string $deletedPath = null): void
    {
        $after = $this->snapshot();
        $table = $this->table($kind);
        $id = $this->resourceId($kind);
        $old = array_values(array_filter($before[$table], static fn ($row): bool => (int) $row['id'] === $id))[0];
        $current = array_values(array_filter($after[$table], static fn ($row): bool => (int) $row['id'] === $id))[0];
        $this->assertNotNull($current['secure_deleted_at']);
        $this->assertSame(211, (int) $current['secure_deleted_by_user_id']);
        $this->assertSame(self::REASON, $current['secure_deletion_reason']);
        foreach (['secure_deleted_at', 'secure_deleted_by_user_id', 'secure_deletion_reason', 'updated_at'] as $field) {
            $old[$field] = $current[$field];
        }
        $this->assertSame($old, $current);
        foreach ($before[$table] as &$row) {
            if ((int) $row['id'] === $id) {
                $row = $current;
            }
        }
        unset($row);
        $oldIds = array_column($before['offline_scanner_session_audits'], 'id');
        $extra = array_values(array_filter($after['offline_scanner_session_audits'], static fn ($row): bool => ! in_array($row['id'], $oldIds, true)));
        $retained = array_values(array_filter($after['offline_scanner_session_audits'], static fn ($row): bool => in_array($row['id'], $oldIds, true)));
        $this->assertSame($before['offline_scanner_session_audits'], $retained);
        $this->assertCount(1, $extra);
        $audit = $extra[0];
        $this->assertSame(401, (int) $audit['offline_scanner_session_id']);
        $this->assertSame(301, (int) $audit['event_id']);
        $this->assertSame(211, (int) $audit['actor_user_id']);
        $this->assertSame(OfflineScannerSessionAudit::ACTION_FILE_DELETED, $audit['action']);
        $this->assertSame(self::REASON, $audit['reason']);
        $this->assertNotNull($audit['occurred_at']);
        $metadata = $kind === 'package' ? ['file_type' => 'package', 'package_uuid' => $current['package_uuid']]
            : ['file_type' => 'results', 'import_uuid' => $current['import_uuid'], 'results_export_id' => $current['results_export_id']];
        $metadata += ['file_hash' => $current['file_hash'], 'file_size' => $current['encrypted_file_size'] === null ? null : (int) $current['encrypted_file_size'],
            'storage_disk' => $current['storage_disk'] ?: config('offline_scanner.disk'), 'file_path' => $current[$kind === 'package' ? 'file_path' : 'encrypted_file_path']];
        $this->assertSame($metadata, json_decode($audit['metadata'], true, 32, JSON_THROW_ON_ERROR));
        $before['offline_scanner_session_audits'] = $after['offline_scanner_session_audits'];
        if ($deletedPath !== null) {
            unset($before['_files'][$deletedPath]);
        }
        $this->assertSame($before, $after, 'Only expected deletion metadata, one audit and the selected file may change.');
    }

    private function snapshot(): array
    {
        $rows = [];
        foreach (DB::connection()->selectFromWriteConnection("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name") as $table) {
            $contents = DB::table($table->name)->useWritePdo()->get()->map(static fn ($row) => (array) $row)->all();
            usort($contents, static fn ($a, $b): int => strcmp(json_encode($a), json_encode($b)));
            $rows[$table->name] = $contents;
        }
        $files = Storage::disk(config('offline_scanner.disk'))->allFiles();
        sort($files);
        $rows['_files'] = [];
        foreach ($files as $file) {
            $rows['_files'][$file] = hash('sha256', Storage::disk(config('offline_scanner.disk'))->get($file));
        }

        return $rows;
    }

    private function queries(array &$queries, callable $callback): mixed
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

    private function dml(array $queries): array
    {
        return array_values(array_filter($queries, static fn ($sql): bool => preg_match('/\A\s*(insert|update|delete)\b/i', $sql)));
    }

    private function resourceId(string $kind): int
    {
        return $kind === 'package' ? 501 : 601;
    }

    private function table(string $kind): string
    {
        return $kind === 'package' ? 'offline_scanner_packages' : 'offline_scanner_result_imports';
    }

    private function errorKey(string $kind): string
    {
        return $kind === 'package' ? 'offline_package' : 'offline_results';
    }

    private function successMessage(string $kind): string
    {
        return $kind === 'package' ? 'Offline scanner package file was securely deleted and audited.'
            : 'Offline scanner results file was securely deleted and audited.';
    }
}

class SecureDeletionResultFixtureStorage extends OfflineScannerFileStorage
{
    public array $deleteCalls = [];

    public array $existsCalls = [];

    public function __construct(OfflineScannerSessionAuditService $audits, private readonly bool $returnFalse)
    {
        parent::__construct($audits);
    }

    public function exists(string $path, ?string $disk = null): bool
    {
        $this->existsCalls[] = [$path, $disk];

        return parent::exists($path, $disk);
    }

    public function delete(string $path, ?string $disk = null): bool
    {
        $this->deleteCalls[] = [$path, $disk];

        return $this->returnFalse ? false : parent::delete($path, $disk);
    }
}
