<?php

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $connection = DB::connection($this->getConnection());
        $this->assertSafeConnection($connection);
        $schema = $connection->getSchemaBuilder();
        foreach (['users', 'workspaces'] as $parent) {
            if (! $schema->hasTable($parent) || ! $schema->hasColumns($parent, ['id'])) {
                throw new RuntimeException('Workspace provisioning requires existing user and workspace parents.');
            }
            $primary = false;
            foreach ($schema->getIndexes($parent) as $index) {
                if ($index['primary'] && $index['unique'] && $index['columns'] === ['id']) {
                    $primary = true;
                    break;
                }
            }
            if (! $primary || $connection->table($parent)->useWritePdo()->whereNull('id')->exists()) {
                throw new RuntimeException('Workspace provisioning requires safe parent primary keys.');
            }
        }
        if ($schema->hasTable('workspace_provisionings') || $schema->hasView('workspace_provisionings')) {
            throw new RuntimeException('Workspace provisioning already exists or is partial; review it before proceeding.');
        }

        // Empty expansion only. No customers, membership activation or event adoption.
        // MySQL DDL is non-atomic; partial failures require forward repair.
        $schema->create('workspace_provisionings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('creator_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->char('request_hash', 64);
            $table->char('name_hash', 64);
            $table->timestamps();
            $table->unique(['creator_user_id', 'request_hash'], 'workspace_provisioning_request_unique');
            $table->unique('workspace_id', 'workspace_provisioning_workspace_unique');
        });
    }

    public function down(): void
    {
        $connection = DB::connection($this->getConnection());
        $this->assertSafeConnection($connection);
        $schema = $connection->getSchemaBuilder();
        if (! $schema->hasTable('workspace_provisionings')
            || ! $schema->hasColumns('workspace_provisionings', ['id', 'creator_user_id', 'workspace_id', 'request_hash', 'name_hash', 'created_at', 'updated_at'])) {
            throw new RuntimeException('Workspace provisioning is incomplete; rollback requires review.');
        }
        if ($connection->table('workspace_provisionings')->useWritePdo()->exists()) {
            throw new RuntimeException('Workspace provisioning contains creation records; preserve it during code rollback.');
        }
        $schema->drop('workspace_provisionings');
    }

    private function assertSafeConnection(Connection $connection): void
    {
        if (! in_array($connection->getDriverName(), ['sqlite', 'mysql', 'mariadb'], true)) {
            throw new RuntimeException('Workspace provisioning requires a supported database engine.');
        }
        if ($connection->getDriverName() === 'sqlite' && $connection->transactionLevel() > 0) {
            throw new RuntimeException('Workspace provisioning migration cannot run inside a SQLite transaction.');
        }
    }
};
