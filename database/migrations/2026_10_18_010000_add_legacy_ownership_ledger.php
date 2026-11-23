<?php

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Do not let a future SQLite grammar wrap this guarded migration in a
    // transaction. MySQL DDL remains non-atomic regardless of this setting.
    public $withinTransaction = false;

    public function up(): void
    {
        $connection = DB::connection($this->getConnection());
        $this->assertSafeConnection($connection);
        $schema = $connection->getSchemaBuilder();

        if (! $schema->hasTable('workspaces') || ! $schema->hasColumns('workspaces', ['id'])) {
            throw new RuntimeException('Ownership ledger requires the existing workspace parent schema.');
        }

        // A column name alone cannot establish a safe foreign-key parent in a
        // malformed legacy schema. Primary keys cannot contain expressions;
        // require exactly this declared key and observed non-nullness.
        $primary = false;
        foreach ($schema->getIndexes('workspaces') as $index) {
            if ($index['primary'] && $index['unique'] && $index['columns'] === ['id']) {
                $primary = true;
                break;
            }
        }
        if (! $primary || $connection->table('workspaces')->whereNull('id')->exists()) {
            throw new RuntimeException('Ownership ledger requires a safe workspace primary key.');
        }

        if ($schema->hasTable('legacy_ownership_plans') || $schema->hasView('legacy_ownership_plans')
            || $schema->hasTable('legacy_workspace_mappings') || $schema->hasView('legacy_workspace_mappings')) {
            throw new RuntimeException('Ownership ledger already exists or is partial; review it before proceeding.');
        }

        // No records, approval or ownership changes are created here. A DDL
        // failure can leave partial structures; stop for review and forward
        // repair rather than silently adopting or deleting unknown state.
        $schema->create('legacy_ownership_plans', function (Blueprint $table): void {
            $table->id();
            $table->unsignedTinyInteger('schema_version');
            $table->char('manifest_hash', 64);
            $table->timestamps();
            $table->unique('manifest_hash', 'legacy_plan_hash_unique');
        });

        $schema->create('legacy_workspace_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ownership_plan_id');
            $table->string('manifest_reference', 64);
            $table->foreignId('workspace_id');
            $table->timestamps();
            $table->unique(['ownership_plan_id', 'manifest_reference'], 'legacy_mapping_plan_ref_unique');
            $table->unique('workspace_id', 'legacy_mapping_workspace_unique');
            $table->foreign('ownership_plan_id', 'legacy_mapping_plan_fk')
                ->references('id')->on('legacy_ownership_plans')->restrictOnDelete();
            $table->foreign('workspace_id', 'legacy_mapping_workspace_fk')
                ->references('id')->on('workspaces')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        $connection = DB::connection($this->getConnection());
        $this->assertSafeConnection($connection);
        $schema = $connection->getSchemaBuilder();

        if (! $schema->hasTable('legacy_ownership_plans') || ! $schema->hasTable('legacy_workspace_mappings')
            || ! $schema->hasColumns('legacy_ownership_plans', ['id', 'schema_version', 'manifest_hash', 'created_at', 'updated_at'])
            || ! $schema->hasColumns('legacy_workspace_mappings', ['id', 'ownership_plan_id', 'manifest_reference', 'workspace_id', 'created_at', 'updated_at'])) {
            throw new RuntimeException('Ownership ledger is incomplete; rollback requires review.');
        }

        // Every prerequisite and conservation check precedes the first DDL.
        if ($connection->table('legacy_workspace_mappings')->exists()
            || $connection->table('legacy_ownership_plans')->exists()) {
            throw new RuntimeException('Ownership ledger contains private mapping data; preserve it during code rollback.');
        }

        $schema->drop('legacy_workspace_mappings');
        $schema->drop('legacy_ownership_plans');
    }

    private function assertSafeConnection(Connection $connection): void
    {
        if (! in_array($connection->getDriverName(), ['sqlite', 'mysql', 'mariadb'], true)) {
            throw new RuntimeException('Ownership ledger requires a supported database engine.');
        }

        if ($connection->getDriverName() === 'sqlite' && $connection->transactionLevel() > 0) {
            throw new RuntimeException('Ownership ledger migration cannot run inside a SQLite transaction.');
        }
    }
};
