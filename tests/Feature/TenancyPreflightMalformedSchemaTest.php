<?php

namespace Tests\Feature;

use App\Models\Event;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class TenancyPreflightMalformedSchemaTest extends TestCase
{
    use RefreshDatabase;

    private array $ownedConnections = [];

    protected function tearDown(): void
    {
        foreach ($this->ownedConnections as $name) {
            DB::purge($name);
        }

        parent::tearDown();
    }

    public function test_duplicate_stream_ids_cannot_hide_invalid_guests_beyond_the_chunk_boundary(): void
    {
        $event = Event::factory()->create();
        $connection = $this->isolatedSchema('preflight_duplicate_ids');
        $this->replaceGuestsWithoutPrimaryKey($connection);
        $rows = [];
        for ($index = 0; $index < 501; $index++) {
            $rows[] = $this->guestAttributes($event->id, 1, $index);
        }
        $rows[500]['status'] = 'private-invalid-final-status-marker';
        foreach (array_chunk($rows, 100) as $chunk) {
            $connection->table('guests')->insert($chunk);
        }
        $before = $this->snapshot($connection);

        [$report, $output] = $this->runPreflight('preflight_duplicate_ids', 2);

        $this->assertSame('incomplete', $report['status']);
        $this->assertSame(501, $report['tables']['guests']['rows']);
        $this->assertSame([], $report['tables']['guests']['missing_columns']);
        $this->assertSame([
            'guests_invalid_stream_key' => ['severity' => 'blocker', 'count' => 1],
        ], $report['findings']);
        $this->assertStringNotContainsString('private-invalid-final-status-marker', $output);
        $this->assertSame($before, $this->snapshot($connection));
    }

    public function test_a_nullable_unique_stream_id_does_not_prove_every_guest_will_be_visited(): void
    {
        $event = Event::factory()->create();
        $connection = $this->isolatedSchema('preflight_null_id');
        $this->replaceGuestsWithoutPrimaryKey($connection, true);
        $connection->table('guests')->insert($this->guestAttributes($event->id, null, 1));
        $before = $this->snapshot($connection);

        [$report] = $this->runPreflight('preflight_null_id', 2);

        $this->assertSame('incomplete', $report['status']);
        $this->assertSame(1, $report['tables']['guests']['rows']);
        $this->assertSame([], $report['tables']['guests']['missing_columns']);
        $this->assertSame([
            'guests_invalid_stream_key' => ['severity' => 'blocker', 'count' => 1],
        ], $report['findings']);
        $this->assertSame($before, $this->snapshot($connection));
    }

    public function test_mysql_expression_index_parts_cannot_be_discarded_when_proving_email_uniqueness(): void
    {
        $base = $this->isolatedSchema('preflight_metadata_source');
        $base->statement('DROP INDEX users_email_unique');
        $base->statement('CREATE UNIQUE INDEX fixture_email_unique ON users(email, lower(name))');

        // SQLite and Laravel's MySQL metadata both omit the expression slot in
        // normalized index columns. Keep real SQLite query/schema execution,
        // and model only MySQL's raw information_schema rows and driver label.
        // This proves the guard against lossy metadata, not a MySQL rehearsal.
        $connection = Mockery::mock(SQLiteConnection::class.'[getDriverName]', [
            $base->getPdo(), ':memory:', '', $base->getConfig(),
        ]);
        $connection->shouldReceive('getDriverName')->andReturn('mysql');
        $this->assertInstanceOf(SQLiteConnection::class, $connection);
        DB::extend('preflight_metadata', fn () => $connection);
        config(['database.connections.preflight_mysql_metadata' => [
            'driver' => 'preflight_metadata', 'database' => ':memory:', 'prefix' => '',
        ]]);
        $this->ownedConnections[] = 'preflight_mysql_metadata';
        $connection->statement("ATTACH DATABASE ':memory:' AS information_schema");
        $connection->statement('CREATE TABLE information_schema.statistics (table_schema TEXT, table_name TEXT, index_name TEXT, column_name TEXT)');
        foreach ($base->getSchemaBuilder()->getTables() as $table) {
            foreach ($base->getSchemaBuilder()->getIndexes($table['name']) as $index) {
                foreach ($index['columns'] as $column) {
                    $connection->table('information_schema.statistics')->insert([
                        'table_schema' => ':memory:', 'table_name' => $table['name'],
                        'index_name' => strtoupper($index['name']), 'column_name' => $column,
                    ]);
                }
            }
        }
        $connection->table('information_schema.statistics')->insert([
            'table_schema' => ':memory:', 'table_name' => 'users',
            'index_name' => 'FIXTURE_EMAIL_UNIQUE', 'column_name' => null,
        ]);
        $normalized = collect($base->getSchemaBuilder()->getIndexes('users'))->firstWhere('name', 'fixture_email_unique');
        $this->assertSame(['email'], $normalized['columns']);
        $before = $this->snapshot($connection);
        $metadataBefore = $connection->table('information_schema.statistics')->get()->all();

        [$report] = $this->runPreflight('preflight_mysql_metadata', 1);

        $this->assertSame('complete', $report['status']);
        $this->assertSame('mysql', $report['database']['driver']);
        $this->assertSame([
            'index_users_email_unique' => ['severity' => 'blocker', 'count' => 1],
        ], $report['findings']);
        $this->assertSame($before, $this->snapshot($connection));
        $this->assertEquals($metadataBefore, $connection->table('information_schema.statistics')->get()->all());
    }

    private function isolatedSchema(string $name): Connection
    {
        config(['database.connections.'.$name => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => false,
        ]]);
        $this->ownedConnections[] = $name;
        $connection = DB::connection($name);
        $source = DB::connection('sqlite');
        // Copy the actual migrated test schema into an independent in-memory
        // database so malformed fixtures cannot affect RefreshDatabase's PDO.
        $objects = $source->select("SELECT type, name, sql FROM sqlite_master WHERE type IN ('table', 'index') AND name NOT LIKE 'sqlite_%' AND sql IS NOT NULL ORDER BY CASE type WHEN 'table' THEN 0 ELSE 1 END, name");
        foreach ($objects as $object) {
            $connection->statement($object->sql);
        }
        foreach ($objects as $object) {
            if ($object->type !== 'table') {
                continue;
            }
            foreach ($source->table($object->name)->get() as $row) {
                $connection->table($object->name)->insert((array) $row);
            }
        }

        return $connection;
    }

    private function replaceGuestsWithoutPrimaryKey(Connection $connection, bool $uniqueNullableId = false): void
    {
        $schema = $connection->getSchemaBuilder();
        $schema->drop('guests');
        $schema->create('guests', function (Blueprint $table) use ($uniqueNullableId): void {
            $id = $table->integer('id')->nullable();
            if ($uniqueNullableId) {
                $id->unique();
            }
            $table->integer('event_id');
            $table->string('manual_code');
            $table->string('pass_type');
            $table->integer('allowed_entries');
            $table->integer('used_entries');
            $table->string('status');
            $table->unique(['event_id', 'manual_code']);
        });
    }

    private function guestAttributes(int $event, ?int $id, int $code): array
    {
        return [
            'id' => $id, 'event_id' => $event,
            'manual_code' => str_pad((string) $code, 4, '0', STR_PAD_LEFT),
            'pass_type' => 'single', 'allowed_entries' => 1, 'used_entries' => 0, 'status' => 'active',
        ];
    }

    private function runPreflight(string $connection, int $expectedExit): array
    {
        $exit = Artisan::call('tenancy:preflight', ['--connection' => $connection]);
        $output = Artisan::output();
        $this->assertSame($expectedExit, $exit, $output);
        $report = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $this->assertFalse($report['rollout_ready']);

        return [$report, $output];
    }

    private function snapshot(Connection $connection): array
    {
        $schema = $connection->select('SELECT type, name, tbl_name, sql FROM sqlite_master ORDER BY type, name');
        $contents = [];
        foreach ($schema as $object) {
            if ($object->type !== 'table') {
                continue;
            }
            $rows = $connection->table($object->name)->get()->map(fn (object $row): array => (array) $row)->all();
            usort($rows, fn (array $left, array $right): int => strcmp(
                json_encode($left, JSON_THROW_ON_ERROR), json_encode($right, JSON_THROW_ON_ERROR),
            ));
            $contents[$object->name] = $rows;
        }

        return ['schema' => array_map(fn (object $row): array => (array) $row, $schema), 'contents' => $contents];
    }
}
