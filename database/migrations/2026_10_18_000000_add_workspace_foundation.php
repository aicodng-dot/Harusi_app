<?php

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // SQLite must rebuild parent tables outside a transaction to suspend its
    // foreign-key checks safely. MySQL DDL is not made atomic by this flag.
    public $withinTransaction = false;

    public function up(): void
    {
        $connection = DB::connection($this->getConnection());
        $this->assertSafeConnection($connection);
        $schema = $connection->getSchemaBuilder();

        if (! $schema->hasColumns('users', ['id']) || ! $schema->hasColumns('events', ['id'])) {
            throw new RuntimeException('Workspace expansion requires the existing users and events schema.');
        }

        if ($schema->hasTable('workspaces') || $schema->hasView('workspaces')
            || $schema->hasTable('workspace_users') || $schema->hasView('workspace_users')
            || $schema->hasColumn('events', 'workspace_id')
            || $schema->hasColumn('users', 'is_platform_admin')) {
            throw new RuntimeException('Workspace expansion already exists or is partial; review it before proceeding.');
        }

        // Never adopt an unknown expansion or assign owners from legacy roles.
        // DDL failures can leave a partial expansion on the supported engines;
        // stop for review instead of attempting an automatic destructive repair.
        $schema->create('workspaces', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 255);
            $table->timestamps();
        });

        $schema->create('workspace_users', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->foreignId('user_id')->index()->constrained('users')->restrictOnDelete();
            $table->enum('role', ['owner', 'manager']);
            $table->boolean('is_active')->default(false);
            $table->timestamps();
            $table->unique(['workspace_id', 'user_id']);
        });

        $schema->table('events', function (Blueprint $table): void {
            $table->foreignId('workspace_id')->nullable()->index()->constrained('workspaces')->restrictOnDelete();
        });

        $schema->table('users', function (Blueprint $table): void {
            $table->boolean('is_platform_admin')->default(false);
        });
    }

    public function down(): void
    {
        $connection = DB::connection($this->getConnection());
        $this->assertSafeConnection($connection);
        $schema = $connection->getSchemaBuilder();

        if (! $schema->hasTable('workspaces') || ! $schema->hasTable('workspace_users')
            || ! $schema->hasColumns('workspaces', ['id', 'name', 'created_at', 'updated_at'])
            || ! $schema->hasColumns('workspace_users', ['id', 'workspace_id', 'user_id', 'role', 'is_active', 'created_at', 'updated_at'])
            || ! $schema->hasColumns('events', ['workspace_id'])
            || ! $schema->hasColumns('users', ['is_platform_admin'])) {
            throw new RuntimeException('Workspace expansion is incomplete; rollback requires review.');
        }

        // All conservation guards run before the first DDL statement. Retain a
        // populated expansion when rolling application code back.
        if ($connection->table('workspace_users')->exists()
            || $connection->table('workspaces')->exists()
            || $connection->table('events')->whereNotNull('workspace_id')->exists()
            || $connection->table('users')->where(function (Builder $query): void {
                $query->where('is_platform_admin', '!=', false)->orWhereNull('is_platform_admin');
            })->exists()) {
            throw new RuntimeException('Workspace expansion contains ownership, membership or platform data; preserve it during code rollback.');
        }

        $schema->table('events', function (Blueprint $table): void {
            $table->dropForeign(['workspace_id']);
            $table->dropIndex(['workspace_id']);
            $table->dropColumn('workspace_id');
        });

        $schema->table('users', function (Blueprint $table): void {
            $table->dropColumn('is_platform_admin');
        });

        $schema->drop('workspace_users');
        $schema->drop('workspaces');
    }

    private function assertSafeConnection(Connection $connection): void
    {
        if (! in_array($connection->getDriverName(), ['sqlite', 'mysql', 'mariadb'], true)) {
            throw new RuntimeException('Workspace expansion requires a supported database engine.');
        }

        if ($connection->getDriverName() === 'sqlite' && $connection->transactionLevel() > 0) {
            throw new RuntimeException('Workspace expansion cannot rebuild SQLite parent tables inside a transaction.');
        }
    }
};
