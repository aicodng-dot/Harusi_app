<?php

namespace Tests\Feature;

use App\Services\Tenancy\LegacyDataInventory;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OwnershipLedgerInventoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_exposes_ledger_counts_without_private_resolution_or_approval(): void
    {
        $this->assertDatabaseCount('legacy_ownership_plans', 0);
        $this->assertDatabaseCount('legacy_workspace_mappings', 0);
        $workspace = DB::table('workspaces')->insertGetId(['name' => 'Private ledger workspace marker']);
        $plan = DB::table('legacy_ownership_plans')->insertGetId(['schema_version' => 1, 'manifest_hash' => str_repeat('a', 64)]);
        DB::table('legacy_workspace_mappings')->insert([
            'ownership_plan_id' => $plan, 'manifest_reference' => 'private-ledger-reference-marker', 'workspace_id' => $workspace,
        ]);
        $connection = DB::connection('sqlite');
        $before = $this->snapshot($connection);

        $exit = Artisan::call('tenancy:preflight', ['--connection' => 'sqlite']);
        $output = Artisan::output();
        $report = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit, $output);
        $this->assertSame('complete', $report['status']);
        $this->assertCount(32, $report['tables']);
        foreach (['legacy_ownership_plans', 'legacy_workspace_mappings'] as $table) {
            $this->assertSame(['present' => true, 'rows' => 1, 'missing_columns' => []], $report['tables'][$table]);
        }
        foreach ([str_repeat('a', 64), 'private-ledger-reference-marker', 'Private ledger workspace marker'] as $privateValue) {
            $this->assertStringNotContainsString($privateValue, $output);
        }
        $this->assertFalse($report['rollout_ready']);
        $this->assertSame('unverified', $report['gates']['ownership_mapping']);
        $this->assertSame($before, $this->snapshot($connection));
        $this->assertSame(0, DB::table('users')->where('is_platform_admin', true)->count());
        $this->assertSame(0, DB::table('workspace_users')->count());
        $this->assertSame(0, DB::table('events')->whereNotNull('workspace_id')->count());
    }

    public function test_missing_ledger_columns_are_incomplete_without_installing_or_reading_private_values(): void
    {
        config(['database.connections.ledger_inventory_partial' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        $connection = DB::connection('ledger_inventory_partial');
        try {
            foreach (['legacy_ownership_plans', 'legacy_workspace_mappings'] as $table) {
                $connection->getSchemaBuilder()->create($table, function (Blueprint $blueprint): void {
                    $blueprint->id();
                    $blueprint->string('private_extra');
                });
                $connection->table($table)->insert(['private_extra' => 'private-ledger-value-marker']);
            }
            $before = $this->snapshot($connection);

            $report = app(LegacyDataInventory::class)->inspect($connection);

            $this->assertSame('incomplete', $report['status']);
            $this->assertSame(['schema_version', 'manifest_hash'], $report['tables']['legacy_ownership_plans']['missing_columns']);
            $this->assertSame(['ownership_plan_id', 'manifest_reference', 'workspace_id'], $report['tables']['legacy_workspace_mappings']['missing_columns']);
            $this->assertStringNotContainsString('private-', json_encode($report, JSON_THROW_ON_ERROR));
            $this->assertSame($before, $this->snapshot($connection));
            $this->assertFalse($report['rollout_ready']);
        } finally {
            DB::purge('ledger_inventory_partial');
        }
    }

    private function snapshot(Connection $connection): array
    {
        $schema = $connection->select('SELECT type, name, tbl_name, sql FROM sqlite_master ORDER BY type, name');
        $rows = [];
        foreach ($schema as $object) {
            if ($object->type !== 'table') {
                continue;
            }
            $contents = $connection->table($object->name)->get()->map(fn (object $row): array => (array) $row)->all();
            usort($contents, fn (array $left, array $right): int => strcmp(json_encode($left, JSON_THROW_ON_ERROR), json_encode($right, JSON_THROW_ON_ERROR)));
            $rows[$object->name] = $contents;
        }

        return ['schema' => array_map(fn (object $row): array => (array) $row, $schema), 'rows' => $rows];
    }
}
