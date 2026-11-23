<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\OfflineScannerImportConflict;
use App\Models\OfflineScannerImportedLog;
use App\Models\OfflineScannerPackage;
use App\Models\OfflineScannerResultImport;
use App\Models\OfflineScannerSession;
use App\Models\User;
use App\Services\Tenancy\LegacyOfflineResourceResolver;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LegacyOfflineResourceBoundaryTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('actors')]
    public function test_private_preview_session_is_404_before_missing_file_validation(bool $platform): void
    {
        $f = $this->fixture($platform);
        $before = $this->snapshot();
        $this->actingAs($f['admin'])->withSession(['selected_event_id' => 301])
            ->postJson(route('admin.offline-scanner-results.preview'), ['offline_scanner_session_id' => 402])
            ->assertNotFound()->assertSessionHas('selected_event_id', 301);
        $this->assertSame($before, $this->snapshot());
    }

    public static function actors(): array
    {
        return ['legacy staff' => [false], 'platform flag supplies no bypass' => [true]];
    }

    #[DataProvider('boundActions')]
    public function test_every_bound_action_denies_private_parent_before_policies_payload_or_effects(string $method, string $route, string $parameter, int $id): void
    {
        $f = $this->fixture();
        $before = $this->snapshot();
        $sql = [];
        $response = $this->queries($sql, fn () => $this->actingAs($f['admin'])
            ->withSession(['selected_event_id' => 301, 'guest_import' => ['marker' => 'preserve']])
            ->json($method, route($route, [$parameter => $id]), []));
        $response->assertNotFound()->assertSessionHas('selected_event_id', 301)->assertSessionHas('guest_import.marker', 'preserve');
        $this->assertSame($before, $this->snapshot());
        $this->assertNoPayloadOrWrites($sql);
    }

    public static function boundActions(): array
    {
        return [
            'session show' => ['GET', 'admin.offline-scanner-sessions.show', 'offlineScannerSession', 402],
            'session cancel' => ['POST', 'admin.offline-scanner-sessions.cancel', 'offlineScannerSession', 402],
            'session close policy before conflict query' => ['POST', 'admin.offline-scanner-sessions.close', 'offlineScannerSession', 402],
            'package generate' => ['POST', 'admin.offline-scanner-sessions.generate-package', 'offlineScannerSession', 402],
            'package download' => ['GET', 'admin.offline-scanner-packages.download', 'offlineScannerPackage', 502],
            'package archive' => ['POST', 'admin.offline-scanner-packages.archive', 'offlineScannerPackage', 502],
            'package destroy' => ['DELETE', 'admin.offline-scanner-packages.destroy', 'offlineScannerPackage', 502],
            'import show' => ['GET', 'admin.offline-scanner-results.show', 'resultImport', 602],
            'import logs' => ['GET', 'admin.offline-scanner-results.logs', 'resultImport', 602],
            'import conflicts' => ['GET', 'admin.offline-scanner-results.conflicts', 'resultImport', 602],
            'import confirm' => ['POST', 'admin.offline-scanner-results.confirm', 'resultImport', 602],
            'import archive' => ['POST', 'admin.offline-scanner-results.archive', 'resultImport', 602],
            'import destroy' => ['DELETE', 'admin.offline-scanner-results.destroy', 'resultImport', 602],
            'conflict show' => ['GET', 'admin.offline-scanner-conflicts.show', 'conflict', 702],
            'conflict resolve' => ['POST', 'admin.offline-scanner-conflicts.resolve', 'conflict', 702],
        ];
    }

    public function test_foreign_unowned_session_preview_and_import_event_mismatch_are_hidden(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['admin'])->withSession(['selected_event_id' => 301])
            ->postJson(route('admin.offline-scanner-results.preview'), ['offline_scanner_session_id' => 403])->assertNotFound();
        DB::table('offline_scanner_result_imports')->where('id', 601)->update(['event_id' => 302]);
        $before = $this->snapshot();
        $sql = [];
        $this->queries($sql, fn () => $this->get(route('admin.offline-scanner-results.show', 601))->assertNotFound());
        $this->assertNoPayloadOrWrites($sql);
        $this->assertSame($before, $this->snapshot());
    }

    #[DataProvider('parentChanges')]
    public function test_controller_rechecks_primary_parent_after_the_selected_event_gateway(string $route, string $parameter, int $id): void
    {
        $f = $this->fixture();
        $changed = false;
        $expected = null;
        DB::connection()->beforeExecuting(function (string $sql) use (&$changed, &$expected): void {
            if (! $changed && preg_match('/\bfrom\s+"offline_scanner_[a-z_]+"\s+as\s+"r"/i', $sql)) {
                $changed = true;
                DB::table('events')->where('id', 301)->update(['workspace_id' => 101]);
                $expected = $this->snapshot();
            }
        });
        $this->actingAs($f['admin'])->withSession(['selected_event_id' => 301])
            ->get(route($route, [$parameter => $id]))->assertNotFound();
        $this->assertTrue($changed);
        $this->assertSame($expected, $this->snapshot());
    }

    public static function parentChanges(): array
    {
        return [
            'session' => ['admin.offline-scanner-sessions.show', 'offlineScannerSession', 401],
            'package' => ['admin.offline-scanner-packages.download', 'offlineScannerPackage', 501],
            'import' => ['admin.offline-scanner-results.show', 'resultImport', 601],
            'conflict' => ['admin.offline-scanner-conflicts.show', 'conflict', 701],
        ];
    }

    public function test_generated_package_must_belong_to_the_same_session_before_download_effects(): void
    {
        $f = $this->fixture();
        OfflineScannerSession::factory()->create(['id' => 404, 'event_id' => 301, 'created_by_user_id' => 211]);
        DB::table('offline_scanner_packages')->where('id', 501)->update(['offline_scanner_session_id' => 404]);
        $before = $this->snapshot();
        $sql = [];
        $this->queries($sql, fn () => $this->actingAs($f['admin'])->withSession(['selected_event_id' => 301])
            ->postJson(route('admin.offline-scanner-sessions.generate-package', 401), []))->assertNotFound();
        $this->assertSame($before, $this->snapshot());
        $this->assertNoPayloadOrWrites($sql);
    }

    #[DataProvider('legacyStatuses')]
    public function test_exact_primary_chains_remain_readable_without_mutation_or_internal_aliases(string $status): void
    {
        $this->fixture();
        DB::table('events')->where('id', 301)->update(['status' => $status]);
        $before = $this->snapshot();
        $sql = [];
        $resolver = app(LegacyOfflineResourceResolver::class);
        $heads = $this->queries($sql, fn () => [
            $resolver->session('401', '301'), $resolver->package('501', 301),
            $resolver->resultImport(601, '301'), $resolver->conflict(701, 301),
        ]);
        foreach ($heads as $head) {
            $this->assertNotNull($head);
            $this->assertFalse($head->isDirty());
            $this->assertSame([], $head->getRelations());
            foreach (array_keys($head->getAttributes()) as $key) {
                $this->assertFalse(str_starts_with($key, '_legacy_'));
            }
        }
        $this->assertSame($before, $this->snapshot());
        $this->assertSame([], array_filter($sql, fn ($s) => preg_match('/\A\s*(insert|update|delete|create|alter|drop)\b/i', $s)));
    }

    public static function legacyStatuses(): array
    {
        return ['active' => ['active'], 'archived history' => ['archived'], 'cancelled history' => ['cancelled']];
    }

    #[DataProvider('invalidIds')]
    public function test_ambiguous_input_ids_refuse_before_any_connection_query(mixed $bad): void
    {
        $sql = [];
        $resolver = app(LegacyOfflineResourceResolver::class);
        $this->queries($sql, function () use ($resolver, $bad): void {
            foreach (['session' => 401, 'package' => 501, 'resultImport' => 601, 'conflict' => 701] as $method => $id) {
                $this->assertNull($resolver->$method($bad, 301));
                $this->assertNull($resolver->$method($id, $bad));
            }
        });
        $this->assertSame([], $sql);
    }

    public static function invalidIds(): array
    {
        return ['null' => [null], 'zero' => [0], 'negative' => [-1], 'true' => [true], 'false' => [false], 'float' => [401.0],
            'zero text' => ['0'], 'padded' => ['0401'], 'signed' => ['+401'], 'exponent' => ['4.01e2'],
            'suffix' => ['401x'], 'space' => [' 401'], 'overflow' => ['9223372036854775808'], 'array' => [[]]];
    }

    #[DataProvider('inconsistentLinks')]
    public function test_inconsistent_import_conflict_or_log_parent_is_never_adopted(string $table, int $id, array $change, string $method, int $resource): void
    {
        $this->fixture();
        DB::table($table)->where('id', $id)->update($change);
        $before = $this->snapshot();
        $resolver = app(LegacyOfflineResourceResolver::class);
        $this->assertNull($resolver->$method($resource, 301));
        $this->assertSame($before, $this->snapshot());
    }

    public static function inconsistentLinks(): array
    {
        return [
            'session now foreign' => ['offline_scanner_sessions', 401, ['event_id' => 303, 'status' => 'cancelled'], 'session', 401],
            'package parent now owned' => ['offline_scanner_packages', 501, ['offline_scanner_session_id' => 402], 'package', 501],
            'import event mismatch' => ['offline_scanner_result_imports', 601, ['event_id' => 302], 'resultImport', 601],
            'import session mismatch' => ['offline_scanner_result_imports', 601, ['offline_scanner_session_id' => 402], 'resultImport', 601],
            'conflict event mismatch' => ['offline_scanner_import_conflicts', 701, ['event_id' => 302], 'conflict', 701],
            'conflict import now owned' => ['offline_scanner_import_conflicts', 701, ['offline_scanner_result_import_id' => 602], 'conflict', 701],
            'log import mismatch' => ['offline_scanner_imported_logs', 801, ['offline_scanner_result_import_id' => 602], 'conflict', 701],
            'log session mismatch' => ['offline_scanner_imported_logs', 801, ['offline_scanner_session_id' => 402], 'conflict', 701],
        ];
    }

    public function test_primary_resolver_has_no_replica_attempt_and_null_conflict_log_is_valid(): void
    {
        $this->fixture();
        DB::table('offline_scanner_import_conflicts')->where('id', 701)->update(['offline_scanner_imported_log_id' => null]);
        $sourceBefore = $this->snapshot();
        $default = DB::getDefaultConnection();
        $db = $this->clonePrimaryFixture(DB::connection());
        $this->assertSame(0, $db->transactionLevel());
        DB::setDefaultConnection($db->getName());
        $read = $db->getReadPdo();
        $attempts = 0;
        $db->setReadPdo(static function () use (&$attempts): never {
            $attempts++;
            throw new \RuntimeException('private-replica');
        });
        try {
            $controlFailed = false;
            try {
                $db->table('offline_scanner_sessions')->where('id', 401)->value('id');
            } catch (\Throwable) {
                $controlFailed = true;
            }
            $this->assertTrue($controlFailed);
            $this->assertSame(1, $attempts);
            $attempts = 0;
            $resolver = app(LegacyOfflineResourceResolver::class);
            foreach (['session' => 401, 'package' => 501, 'resultImport' => 601, 'conflict' => 701] as $method => $id) {
                $this->assertNotNull($resolver->$method($id, 301));
                $this->assertNull($resolver->$method($id, 303));
                $this->assertNull($resolver->$method(99999, 301));
            }
        } finally {
            $db->setReadPdo($read);
            DB::setDefaultConnection($default);
            DB::purge($db->getName());
        }
        $this->assertSame(0, $attempts);
        $this->assertSame($sourceBefore, $this->snapshot());
    }

    private function clonePrimaryFixture(Connection $source): Connection
    {
        $name = 'legacy_offline_primary';
        config(['database.connections.'.$name => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]]);
        $db = DB::connection($name);
        $objects = $source->selectFromWriteConnection("SELECT type, name, sql FROM sqlite_master WHERE type IN ('table','index') AND name NOT LIKE 'sqlite_%' AND sql IS NOT NULL ORDER BY CASE type WHEN 'table' THEN 0 ELSE 1 END, name");
        foreach ($objects as $object) {
            $db->statement($object->sql);
        }
        foreach ($objects as $object) {
            if ($object->type === 'table') {
                $generated = [];
                foreach ($source->selectFromWriteConnection('SELECT name, hidden FROM pragma_table_xinfo(?)', [$object->name]) as $column) {
                    if ($column->hidden !== 0) {
                        $generated[$column->name] = true;
                    }
                }
                foreach ($source->table($object->name)->useWritePdo()->get() as $row) {
                    $db->table($object->name)->insert(array_diff_key((array) $row, $generated));
                }
            }
        }
        $db->getSchemaBuilder()->enableForeignKeyConstraints();

        return $db;
    }

    private function queries(array &$sql, callable $callback): mixed
    {
        $db = DB::connection();
        $original = $db->getEventDispatcher();
        $dispatcher = new Dispatcher($this->app);
        $dispatcher->listen(QueryExecuted::class, static function (QueryExecuted $event) use (&$sql): void {
            $sql[] = $event->sql;
        });
        $db->setEventDispatcher($dispatcher);
        try {
            return $callback();
        } finally {
            $db->setEventDispatcher($original);
        }
    }

    private function assertNoPayloadOrWrites(array $sql): void
    {
        foreach ($sql as $query) {
            $this->assertDoesNotMatchRegularExpression('/\A\s*(insert|update|delete|create|alter|drop)\b/i', $query);
            $this->assertDoesNotMatchRegularExpression('/\bfrom\s+["`]?\b(guests|offline_scanner_result_devices|offline_scanner_final_guest_states|offline_scanner_imported_logs|offline_scanner_session_audits)\b/i', $query);
        }
    }

    private function fixture(bool $platform = false): array
    {
        Storage::fake(config('offline_scanner.disk'));
        DB::table('workspaces')->insert(['id' => 101, 'name' => 'Private ownership']);
        foreach ([301 => null, 302 => 101, 303 => null] as $id => $workspace) {
            Event::factory()->create(['id' => $id, 'workspace_id' => $workspace, 'event_name' => 'Synthetic event '.$id]);
        }
        $admin = User::factory()->create(['id' => 211, 'role' => 'admin', 'is_platform_admin' => $platform]);
        $scanner = User::factory()->create(['id' => 212, 'role' => 'scanner', 'event_id' => 301]);
        foreach ([401 => 301, 402 => 302, 403 => 303] as $id => $event) {
            OfflineScannerSession::factory()->active()->create(['id' => $id, 'event_id' => $event,
                'created_by_user_id' => $admin->id, 'master_scanner_user_id' => $scanner->id]);
        }
        foreach ([501 => 401, 502 => 402] as $id => $session) {
            OfflineScannerPackage::factory()->create(['id' => $id, 'offline_scanner_session_id' => $session, 'generated_by_user_id' => $admin->id]);
            DB::table('offline_scanner_sessions')->where('id', $session)->update(['package_export_id' => $id]);
            $package = OfflineScannerPackage::query()->findOrFail($id);
            Storage::disk(config('offline_scanner.disk'))->put($package->file_path, 'invented-file-marker-'.$id);
        }
        foreach ([601 => [401, 301], 602 => [402, 302]] as $id => [$session, $event]) {
            OfflineScannerResultImport::factory()->create(['id' => $id, 'offline_scanner_session_id' => $session,
                'event_id' => $event, 'uploaded_by_user_id' => $admin->id, 'status' => OfflineScannerResultImport::STATUS_PREVIEW_READY]);
        }
        OfflineScannerImportedLog::factory()->create(['id' => 801, 'offline_scanner_result_import_id' => 601,
            'offline_scanner_session_id' => 401, 'scanner_user_id' => $scanner->id]);
        foreach ([701 => [601, 301, 801], 702 => [602, 302, null]] as $id => [$import, $event, $log]) {
            OfflineScannerImportConflict::factory()->create(['id' => $id, 'offline_scanner_result_import_id' => $import,
                'event_id' => $event, 'offline_scanner_imported_log_id' => $log]);
        }

        return ['admin' => $admin];
    }

    private function snapshot(): array
    {
        $db = DB::connection();
        $result = [];
        foreach ($db->selectFromWriteConnection("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name") as $table) {
            $rows = $db->table($table->name)->useWritePdo()->get()->map(static fn ($r) => (array) $r)->all();
            usort($rows, static fn ($a, $b) => strcmp(json_encode($a), json_encode($b)));
            $result[$table->name] = $rows;
        }

        $result['_fake_files'] = [];
        foreach (Storage::disk(config('offline_scanner.disk'))->allFiles() as $file) {
            $result['_fake_files'][$file] = hash('sha256', Storage::disk(config('offline_scanner.disk'))->get($file));
        }
        ksort($result['_fake_files']);

        return $result;
    }
}
